<?php

// Mage_ImportExport_Model_Export_Entity_Product
class Flagbit_MEP_Model_Export_Entity_Product extends Mage_ImportExport_Model_Export_Entity_Product
{

    const CONFIG_KEY_PRODUCT_TYPES = 'global/importexport/export_product_types';

    /**
     * Value that means all entities (e.g. websites, groups etc.)
     */
    const VALUE_ALL = 'all';

    /**
     * Permanent column names.
     *
     * Names that begins with underscore is not an attribute. This name convention is for
     * to avoid interference with same attribute name.
     */
    const COL_STORE = '_store';
    const COL_ATTR_SET = '_attribute_set';
    const COL_TYPE = '_type';
    const COL_CATEGORY = '_category';
    const COL_ROOT_CATEGORY = '_root_category';
    const COL_SKU = 'sku';

    protected $_configurable_delimiter = '|';

    /**
     * Pairs of attribute set ID-to-name.
     *
     * @var array
     */
    protected $_attrSetIdToName = array();

    protected $_attributeMapping = null;

    protected $_threads = array();

    /**
     * Categories ID to text-path hash.
     *
     * @var array
     */
    protected $_categories = array();

    protected $_categoryIds = array();

    /**
     * export limit
     *
     * @var null
     */
    protected $_limit = null;

    /**
     * Root category names for each category
     *
     * @var array
     */
    protected $_rootCategories = array();

    /**
     * Attributes with index (not label) value.
     *
     * @var array
     */
    protected $_indexValueAttributes = array(
        'status',
        'tax_class_id',
        'visibility',
        'enable_googlecheckout',
        'gift_message_available',
        'custom_design'
    );

    /**
     * Permanent entity columns.
     *
     * @var array
     */
    protected $_permanentAttributes = array(self::COL_SKU);

    /**
     * Array of supported product types as keys with appropriate model object as value.
     *
     * @var array
     */
    protected $_productTypeModels = array();

    /**
     * Array of pairs store ID to its code.
     *
     * @var array
     */
    protected $_storeIdToCode = array();

    /**
     * Website ID-to-code.
     *
     * @var array
     */
    protected $_websiteIdToCode = array();

    /**
     * Attribute types
     * @var array
     */
    protected $_attributeTypes = array();

    /**
     * Attribute Models
     * @var array
     */
    protected $_attributeModels = array();

    /**
     * @var Flagbit_MEP_Model_Profil
     */
    protected $_profile = null;

    /**
     * Cache value for parent and children products
     *
     * @var array
     */
    protected $_itemsCache = array('parents' => array(), 'children' => array());

    /**
     * Constructor.
     *
     * @return void
     */
    public function __construct()
    {
        $entityCode = 'catalog_product';
        $this->_entityTypeId = Mage::getSingleton('eav/config')->getEntityType($entityCode)->getEntityTypeId();
        $this->_connection = Mage::getSingleton('core/resource')->getConnection('write');
    }

    /**
     * Initialize attribute sets code-to-id pairs.
     *
     * @return Mage_ImportExport_Model_Export_Entity_Product
     */
    protected function _initAttributeSets()
    {
        $productTypeId = Mage::getModel('catalog/product')->getResource()->getTypeId();
        foreach (Mage::getResourceModel('eav/entity_attribute_set_collection')
                     ->setEntityTypeFilter($productTypeId) as $attributeSet) {
            $this->_attrSetIdToName[$attributeSet->getId()] = $attributeSet->getAttributeSetName();
        }
        return $this;
    }

    /**
     * Initialize categories ID to text-path hash.
     *
     * @return Mage_ImportExport_Model_Export_Entity_Product
     */
    protected function _initCategories()
    {
        $collection = Mage::getResourceModel('catalog/category_collection')->addNameToResult();
        /* @var $collection Mage_Catalog_Model_Resource_Eav_Mysql4_Category_Collection */
        foreach ($collection as $category) {
            $structure = preg_split('#/+#', $category->getPath());
            $pathSize = count($structure);
            if ($pathSize > 1) {
                $path = array();
                $pathIds = array();
                for ($i = 1; $i < $pathSize; $i++) {
                    if(is_a($collection->getItemById($structure[$i]),'Mage_Catalog_Model_Category')){
                        $path[] = $collection->getItemById($structure[$i])->getName();
                        $pathIds[] = $structure[$i];
                    }
                }
                $this->_rootCategories[$category->getId()] = array_shift($path);
                if ($pathSize > 2) {
                    $this->_categories[$category->getId()] = implode($this->getProfile()->getCategoryDelimiter(), $path);
                    $this->_categoryIds[$category->getId()] = $pathIds;
                }
            }

        }
        return $this;
    }

    /**
     * Initialize product type models.
     *
     * @throws Exception
     * @return Mage_ImportExport_Model_Export_Entity_Product
     */
    protected function _initTypeModels()
    {
        $config = Mage::getConfig()->getNode(self::CONFIG_KEY_PRODUCT_TYPES)->asCanonicalArray();
        foreach ($config as $type => $typeModel) {
            if (!($model = Mage::getModel($typeModel, array($this, $type)))) {
                Mage::throwException("Entity type model '{$typeModel}' is not found");
            }
            if (!$model instanceof Mage_ImportExport_Model_Export_Entity_Product_Type_Abstract) {
                Mage::throwException(
                    Mage::helper('importexport')->__('Entity type model must be an instance of Mage_ImportExport_Model_Export_Entity_Product_Type_Abstract')
                );
            }
            if ($model->isSuitable()) {
                $this->_productTypeModels[$type] = $model;
                $this->_disabledAttrs = array_merge($this->_disabledAttrs, $model->getDisabledAttrs());
                $this->_indexValueAttributes = array_merge(
                    $this->_indexValueAttributes, $model->getIndexValueAttributes()
                );
            }
        }
        if (!$this->_productTypeModels) {
            Mage::throwException(Mage::helper('importexport')->__('There are no product types available for export'));
        }
        $this->_disabledAttrs = array_unique($this->_disabledAttrs);

        return $this;
    }

    /**
     * Initialize website values.
     *
     * @return Mage_ImportExport_Model_Export_Entity_Product
     */
    protected function _initWebsites()
    {
        /** @var $website Mage_Core_Model_Website */
        foreach (Mage::app()->getWebsites() as $website) {
            $this->_websiteIdToCode[$website->getId()] = $website->getCode();
        }
        return $this;
    }

    /**
     * Prepare products tier prices
     *
     * @param  array $productIds
     * @return array
     */
    protected function _prepareTierPrices(array $productIds)
    {
        if (empty($productIds)) {
            return array();
        }
        $resource = Mage::getSingleton('core/resource');
        $select = $this->_connection->select()
            ->from($resource->getTableName('catalog/product_attribute_tier_price'))
            ->where('entity_id IN(?)', $productIds);

        $rowTierPrices = array();
        $stmt = $this->_connection->query($select);
        while ($tierRow = $stmt->fetch()) {
            $current_array = array(
                '_tier_price_customer_group' => $tierRow['all_groups']
                        ? self::VALUE_ALL : $tierRow['customer_group_id'],
                '_tier_price_website' => 0 == $tierRow['website_id']
                        ? self::VALUE_ALL
                        : $this->_websiteIdToCode[$tierRow['website_id']],
                '_tier_price_qty' => $tierRow['qty'],
                '_tier_price_price' => $tierRow['value']
            );
            $currentProduct = Mage::getModel('catalog/product')->load($tierRow['entity_id']);
            if ($currentProduct->getTypeId() == 'configurable') {
                foreach ($current_array as $key => &$item) {
                    $item = '';
                }
                $childs = array();
                foreach ($currentProduct->getTypeInstance()->getUsedProducts() as $child) {
                    $childs[] = $child->getId();
                }
                $select = $this->_connection->select()
                    ->from($resource->getTableName('catalog/product_attribute_tier_price'))
                    ->where('entity_id IN(?)', $childs);
                $stmt_child = $this->_connection->query($select);
                while ($tierRowChild = $stmt_child->fetch()) {
                    foreach ($current_array as $key => &$item) {
                        $item .= $tierRowChild[$key] . $this->_configurable_delimiter;
                    }
                }
                foreach ($current_array as &$item) {
                    $item = substr($item, 0, -1);
                }
            }
            $rowTierPrices[$tierRow['entity_id']][] = $current_array;
        }

        return $rowTierPrices;
    }

    /**
     * Prepare products group prices
     *
     * @param  array $productIds
     * @return array
     */
    protected function _prepareGroupPrices(array $productIds)
    {
        if (empty($productIds)) {
            return array();
        }
        $resource = Mage::getSingleton('core/resource');
        $select = $this->_connection->select()
            ->from($resource->getTableName('catalog/product_attribute_group_price'))
            ->where('entity_id IN(?)', $productIds);

        $rowGroupPrices = array();
        $statement = $this->_connection->query($select);
        while ($groupRow = $statement->fetch()) {
            $rowGroupPrices[$groupRow['entity_id']][] = array(
                '_group_price_customer_group' => $groupRow['all_groups']
                        ? self::VALUE_ALL
                        : $groupRow['customer_group_id'],
                '_group_price_website' => (0 == $groupRow['website_id'])
                        ? self::VALUE_ALL
                        : $this->_websiteIdToCode[$groupRow['website_id']],
                '_group_price_price' => $groupRow['value']
            );
        }

        return $rowGroupPrices;
    }

    /**
     * Prepare products media gallery
     *
     * @param  array $productIds
     * @return array
     */
    protected function _prepareMediaGallery(array $productIds)
    {
        if (empty($productIds)) {
            return array();
        }
        $resource = Mage::getSingleton('core/resource');
        $select = $this->_connection->select()
            ->from(
                array('mg' => $resource->getTableName('catalog/product_attribute_media_gallery')),
                array(
                    'mg.entity_id', 'mg.attribute_id', 'filename' => 'mg.value', 'mgv.label',
                    'mgv.position', 'mgv.disabled'
                )
            )
            ->joinLeft(
                array('mgv' => $resource->getTableName('catalog/product_attribute_media_gallery_value')),
                '(mg.value_id = mgv.value_id AND mgv.store_id = 0)',
                array()
            )
            ->where('entity_id IN(?)', $productIds);

        $rowMediaGallery = array();
        $stmt = $this->_connection->query($select);
        while ($mediaRow = $stmt->fetch()) {
            $rowMediaGallery[$mediaRow['entity_id']][] = array(
                '_media_attribute_id' => $mediaRow['attribute_id'],
                '_media_image' => $mediaRow['filename'],
                '_media_lable' => $mediaRow['label'],
                '_media_position' => $mediaRow['position'],
                '_media_is_disabled' => $mediaRow['disabled']
            );
        }

        return $rowMediaGallery;
    }

    /**
     * Prepare catalog inventory
     *
     * @param  array $productIds
     * @return array
     */
    protected function _prepareCatalogInventory(array $productIds)
    {
        if (empty($productIds)) {
            return array();
        }
        $select = $this->_connection->select()
            ->from(Mage::getResourceModel('cataloginventory/stock_item')->getMainTable())
            ->where('product_id IN (?)', $productIds);

        $stmt = $this->_connection->query($select);
        $stockItemRows = array();
        $i = 0;
        while ($stockItemRow = $stmt->fetch()) {
            $productId = $stockItemRow['product_id'];
            $currentProduct = Mage::getModel('catalog/product')->load($stockItemRow['product_id']);
            unset(
            $stockItemRow['item_id'], $stockItemRow['product_id'], $stockItemRow['low_stock_date'],
            $stockItemRow['stock_id'], $stockItemRow['stock_status_changed_automatically']
            );
            if ($currentProduct->getTypeId() == 'configurable') {
                foreach ($stockItemRow as $key => &$stockItem) {
                    $stockItem = '';
                }
                $childs = array();
                foreach ($currentProduct->getTypeInstance()->getUsedProducts() as $child) {
                    $childs[] = $child->getId();
                }
                $select_child = $this->_connection->select()
                    ->from(Mage::getResourceModel('cataloginventory/stock_item')->getMainTable())
                    ->where('product_id IN (?)', $childs);
                $stmt_child = $this->_connection->query($select_child);
                $currentChildValues = array();
                while ($stockItemRowChild = $stmt_child->fetch()) {
                    unset(
                    $stockItemRowChild['item_id'], $stockItemRowChild['product_id'], $stockItemRowChild['low_stock_date'],
                    $stockItemRowChild['stock_id'], $stockItemRowChild['stock_status_changed_automatically']
                    );
                    foreach ($stockItemRow as $key => &$stockItem) {
                        if ($key == 'qty') {
                            $currentValue = intval($stockItemRowChild[$key]);
                        }
                        else {
                            $currentValue = $stockItemRowChild[$key];
                        }
                        if (!isset($currentChildValues[$key])){
                            $currentChildValues[$key] = array();
                        }
                        if (!in_array($currentValue, $currentChildValues[$key])) {
                            $currentChildValues[$key][] = $currentValue;
                        }
                    }
                }
                foreach ($stockItemRow as $key => &$stockItem) {
                    $stockItem = implode($this->_configurable_delimiter, $currentChildValues[$key]);
                }
            }
            $stockItemRows[$productId] = $stockItemRow;
            $i++;
        }
        return $stockItemRows;
    }

    /**
     * Prepare product links
     *
     * @param  array $productIds
     * @return array
     */
    protected function _prepareLinks(array $productIds)
    {
        if (empty($productIds)) {
            return array();
        }
        $resource = Mage::getSingleton('core/resource');
        $adapter = $this->_connection;
        $select = $adapter->select()
            ->from(
                array('cpl' => $resource->getTableName('catalog/product_link')),
                array(
                    'cpl.product_id', 'cpe.sku', 'cpl.link_type_id',
                    'position' => 'cplai.value', 'default_qty' => 'cplad.value'
                )
            )
            ->joinLeft(
                array('cpe' => $resource->getTableName('catalog/product')),
                '(cpe.entity_id = cpl.linked_product_id)',
                array()
            )
            ->joinLeft(
                array('cpla' => $resource->getTableName('catalog/product_link_attribute')),
                $adapter->quoteInto(
                    '(cpla.link_type_id = cpl.link_type_id AND cpla.product_link_attribute_code = ?)',
                    'position'
                ),
                array()
            )
            ->joinLeft(
                array('cplaq' => $resource->getTableName('catalog/product_link_attribute')),
                $adapter->quoteInto(
                    '(cplaq.link_type_id = cpl.link_type_id AND cplaq.product_link_attribute_code = ?)',
                    'qty'
                ),
                array()
            )
            ->joinLeft(
                array('cplai' => $resource->getTableName('catalog/product_link_attribute_int')),
                '(cplai.link_id = cpl.link_id AND cplai.product_link_attribute_id = cpla.product_link_attribute_id)',
                array()
            )
            ->joinLeft(
                array('cplad' => $resource->getTableName('catalog/product_link_attribute_decimal')),
                '(cplad.link_id = cpl.link_id AND cplad.product_link_attribute_id = cplaq.product_link_attribute_id)',
                array()
            )
            ->where('cpl.link_type_id IN (?)', array(
                Mage_Catalog_Model_Product_Link::LINK_TYPE_RELATED,
                Mage_Catalog_Model_Product_Link::LINK_TYPE_UPSELL,
                Mage_Catalog_Model_Product_Link::LINK_TYPE_CROSSSELL,
                Mage_Catalog_Model_Product_Link::LINK_TYPE_GROUPED
            ))
            ->where('cpl.product_id IN (?)', $productIds);

        $stmt = $adapter->query($select);
        $linksRows = array();
        while ($linksRow = $stmt->fetch()) {
            $linksRows[$linksRow['product_id']][$linksRow['link_type_id']][] = array(
                'sku' => $linksRow['sku'],
                'position' => $linksRow['position'],
                'default_qty' => $linksRow['default_qty']
            );
        }

        return $linksRows;
    }

    /**
     * Prepare configurable product data
     *
     * @deprecated since 1.6.1.0
     * @see Mage_Catalog_Model_Resource_Product_Type_Configurable::getConfigurableOptions()
     * @param  array $productIds
     * @return array
     */
    protected function _prepareConfigurableProductData(array $productIds)
    {
        if (empty($productIds)) {
            return array();
        }
        $resource = Mage::getSingleton('core/resource');
        $select = $this->_connection->select()
            ->from(
                array('cpsl' => $resource->getTableName('catalog/product_super_link')),
                array('cpsl.parent_id', 'cpe.sku')
            )
            ->joinLeft(
                array('cpe' => $resource->getTableName('catalog/product')),
                '(cpe.entity_id = cpsl.product_id)',
                array()
            )
            ->where('parent_id IN (?)', $productIds);
        $stmt = $this->_connection->query($select);
        $configurableData = array();
        while ($cfgLinkRow = $stmt->fetch()) {
            $configurableData[$cfgLinkRow['parent_id']][] = array('_super_products_sku' => $cfgLinkRow['sku']);
        }

        return $configurableData;
    }

    /**
     * Prepare configurable product price
     *
     * @deprecated since 1.6.1.0
     * @see Mage_Catalog_Model_Resource_Product_Type_Configurable::getConfigurableOptions()
     * @param  array $productIds
     * @return array
     */
    protected function _prepareConfigurableProductPrice(array $productIds)
    {
        if (empty($productIds)) {
            return array();
        }
        $resource = Mage::getSingleton('core/resource');
        $select = $this->_connection->select()
            ->from(
                array('cpsa' => $resource->getTableName('catalog/product_super_attribute')),
                array(
                    'cpsa.product_id', 'ea.attribute_code', 'eaov.value', 'cpsap.pricing_value', 'cpsap.is_percent'
                )
            )
            ->joinLeft(
                array('cpsap' => $resource->getTableName('catalog/product_super_attribute_pricing')),
                '(cpsap.product_super_attribute_id = cpsa.product_super_attribute_id)',
                array()
            )
            ->joinLeft(
                array('ea' => $resource->getTableName('eav/attribute')),
                '(ea.attribute_id = cpsa.attribute_id)',
                array()
            )
            ->joinLeft(
                array('eaov' => $resource->getTableName('eav/attribute_option_value')),
                '(eaov.option_id = cpsap.value_index AND store_id = 0)',
                array()
            )
            ->where('cpsa.product_id IN (?)', $productIds);
        $configurablePrice = array();
        $stmt = $this->_connection->query($select);
        while ($priceRow = $stmt->fetch()) {
            $configurablePrice[$priceRow['product_id']][] = array(
                '_super_attribute_code' => $priceRow['attribute_code'],
                '_super_attribute_option' => $priceRow['value'],
                '_super_attribute_price_corr' => $priceRow['pricing_value'] . ($priceRow['is_percent'] ? '%' : '')
            );
        }
        return $configurablePrice;
    }

    /**
     * Update data row with information about categories. Return true, if data row was updated
     *
     * @param array $dataRow
     * @param array $rowCategories
     * @param int $productId
     * @return bool
     */
    protected function _updateDataWithCategoryColumns(&$dataRow, &$rowCategories, $productId)
    {
        if (!isset($rowCategories[$productId])) {
            return false;
        }

        // get the deepest Category Path
        $categoryId = null;
        $max = 0;
        foreach((array) $rowCategories[$productId] as $_categoryId){
            if(isset($this->_categoryIds[$_categoryId]) && count($this->_categoryIds[$_categoryId]) > $max){
                $max = $this->_categoryIds[$_categoryId];
                $categoryId = $_categoryId;
            }
        }

        if (isset($this->_rootCategories[$categoryId])) {
            $dataRow[self::COL_ROOT_CATEGORY] = $this->_rootCategories[$categoryId];
        }
        if (isset($this->_categories[$categoryId])) {
            $dataRow[self::COL_CATEGORY] = $this->_categories[$categoryId];
        }

        return true;
    }

    /**
     * get Attribute Mapping
     *
     * @param bool $attributeCode
     * @return array|bool|null
     */
    protected function _getAttributeMapping($attributeCode = false)
    {
        if ($this->_attributeMapping === null) {
            /* @var $attributeMappingCollection Flagbit_MEP_Model_Mysql4_Attribute_Mapping_Collection */
            $attributeMappingCollection = Mage::getResourceModel('mep/attribute_mapping_collection')->load();
            $this->_attributeMapping = array();
            foreach ($attributeMappingCollection as $attributeMapping) {
                $this->_attributeMapping[$attributeMapping->getAttributeCode()] = $attributeMapping;
            }
        }
        if ($attributeCode !== false) {
            if (isset($this->_attributeMapping[$attributeCode])) {
                return $this->_attributeMapping[$attributeCode];
            } else {
                return false;
            }
        }
        return $this->_attributeMapping;
    }

    /**
     * set export Limit
     *
     * @param $limit
     * @return $this
     */
    public function setLimit($limit)
    {
        $this->_limit = $limit;
        return $this;
    }

    /**
     * Export process.
     *
     * @return string
     */
    public function export()
    {

        $this->_initTypeModels()
            ->_initAttributes()
            ->_initAttributeSets()
            ->_initWebsites()
            ->_initCategories();

        //Execution time may be very long
        set_time_limit(0);

        Mage::app()->setCurrentStore(0);


        /** @var $collection Mage_Catalog_Model_Resource_Eav_Mysql4_Product_Collection */
        $validAttrCodes = array();
        $shippingAttrCodes = array();
        $writer = $this->getWriter();


        if ($this->hasProfileId()) {
            /* @var $obj_profile Flagbit_MEP_Model_Profil */
            $obj_profile = $this->getProfile();
            $delimiter = $obj_profile->getDelimiter();
            $this->_configurable_delimiter = $obj_profile->getConfigurableValueDelimiter();
            $enclosure = $obj_profile->getEnclose();

            $this->_storeIdToCode[0] = 'admin';
            $this->_storeIdToCode[$obj_profile->getStoreId()] = Mage::app()->getStore($obj_profile->getStoreId())->getCode();


            $writer->setDelimiter($delimiter);
            $writer->setEnclosure($enclosure);

            // add Twig Templates
            $writer->setTwigTemplate($obj_profile->getTwigHeaderTemplate(), 'header');
            $writer->setTwigTemplate($obj_profile->getTwigContentTemplate(), 'content');
            $writer->setTwigTemplate($obj_profile->getTwigFooterTemplate(), 'footer');

            if ($obj_profile->getOriginalrow() == 1) {
                $writer->setHeaderRow(true);
            } else {
                $writer->setHeaderRow(false);
            }

            // Get Shipping Mapping
            $shipping_id = $obj_profile->getShippingId();
            if (!empty($shipping_id)) {
                $collection = Mage::getModel('mep/shipping_attribute')->getCollection();
                $collection->addFieldToFilter('profile_id', array('eq' => $shipping_id));
                foreach ($collection as $item) {
                    $shippingAttrCodes[$item->getAttributeCode()] = $item;
                }
            }

            // get Field Mapping
            /* @var $mapping Flagbit_MEP_Model_Mysql4_Mapping_Collection */
            $mapping = Mage::getModel('mep/mapping')->getCollection();
            $mapping->addFieldToFilter('profile_id', array('eq' => $this->getProfileId()));
            $mapping->setOrder('position', 'ASC');
            $mapping->load();


            foreach ($mapping->getItems() as $item) {
                $validAttrCodes[] = $item->getToField();
            }

            $offsetProducts = 0;

            Mage::helper('mep/log')->debug('START Filter Rules', $this);
            // LOAD FILTER RULES
            /* @var $ruleObject Flagbit_MEP_Model_Rule */
            $ruleObject = Mage::getModel('mep/rule');
            $rule = unserialize($obj_profile->getConditionsSerialized());
            $filteredProductIds = array();
            if (!empty($rule) && count($rule) > 1) {
                $ruleObject->loadPost(array('conditions' => $rule));
                $ruleObject->setWebsiteIds(array(Mage::app()->getStore($obj_profile->getStoreId())->getWebsiteId()));
                $filteredProductIds = $ruleObject->getMatchingProductIds();

                if(count($filteredProductIds) < 1){
                    return;
                }
            }
            Mage::helper('mep/log')->debug('END Filter Rules', $this);

            /* @var $collection Mage_Catalog_Model_Resource_Product_Collection */
            $collection = $this->_prepareEntityCollection(Mage::getResourceModel('catalog/product_collection'));
            $collection->setStoreId(0)->addStoreFilter($obj_profile->getStoreId());

            if(!empty($filteredProductIds)){
                $collection->addFieldToFilter("entity_id", array('in' => $filteredProductIds));
            }

            $size = $collection->getSize();

            Mage::helper('mep/log')->debug('EXPORT '.$size.' Products', $this);

            // run just a small export for the preview function
            if($this->_limit){
                $this->_exportThread(1, $writer, $this->_limit, $filteredProductIds, $mapping, $shippingAttrCodes);
                return $writer->getContents();
            }

            // to export process in threads for better performance
            $index = 0;
            $limitProducts = 1000;
            $maxThreads = 5;
            while(true){
                $index++;
                $this->_threads[$index] = new Flagbit_MEP_Model_Thread( array($this, '_exportThread') );
                $this->_threads[$index]->start($index, $writer, $limitProducts, $filteredProductIds, $mapping, $shippingAttrCodes);

                // let the first fork go to ensure that the headline is correct set
                if($index == 1){
                    while($this->_threads[$index]->isAlive()){
                        sleep(1);
                    }
                }

                while( count($this->_threads) >= $maxThreads ) {
                    $this->_cleanUpThreads();
                }
                $this->_cleanUpThreads();

                // export is complete
                if($index >= $size/$limitProducts){
                    break;
                }
            }
        }

        // wait for all the threads to finish
        while( !empty( $this->_threads ) ) {
            $this->_cleanUpThreads();
        }
    }

    /**
     * clean up finished threads
     */
    protected function _cleanUpThreads()
    {
        foreach( $this->_threads as $index => $thread ) {
            if( ! $thread->isAlive() ) {
                unset( $this->_threads[$index] );
            }
        }
        // let the CPU do its work
        sleep( 1 );
    }

    /**
     * clean up runtime details
     */
    protected function _cleanUpProcess()
    {
        Mage::reset();
        Mage::app('admin', 'store');

        $entityCode = $this->getEntityTypeCode();
        $this->_entityTypeId = Mage::getSingleton('eav/config')->getEntityType($entityCode)->getEntityTypeId();
        $this->_connection   = Mage::getSingleton('core/resource')->getConnection('write');
    }

    public function _exportThread($offsetProducts, $writer, $limitProducts, $filteredProductIds, $mapping, $shippingAttrCodes) {
        $this->_cleanUpProcess();
        Mage::helper('mep/log')->debug('START Thread '.$offsetProducts, $this);

        $defaultStoreId = Mage_Catalog_Model_Abstract::DEFAULT_STORE_ID;
        /* @var $helperShipping Flagbit_MEP_Helper_Shipping */
        $helperShipping = Mage::helper('mep/shipping');

        $obj_profile = $this->getProfile();
        if($this->_limit !== null &&  $offsetProducts > 1){
            return false;
        }
        $storeId = $obj_profile->getStoreId();
        $collection = $this->_prepareEntityCollection(Mage::getResourceModel('catalog/product_collection'));
        $collection
            ->setStoreId($storeId)
            ->addStoreFilter($obj_profile->getStoreId())
            ->setPage($offsetProducts, $limitProducts);
        if (!empty($filteredProductIds)){
            $collection->addFieldToFilter("entity_id", array('in' => $filteredProductIds));
        }
        $collection->load();
        Mage::log('Item count: ' . count($collection));
        foreach ($collection as $itemId => $item) {
            Mage::log('Type Item: ' . $item->getTypeId());
            foreach ($mapping->getItems() as $mapItem) {
                $attrValues = array();
                $attrInheritance = $mapItem->getInheritance();
                foreach ($mapItem->getAttributeCodeAsArray() as $attrCode) {
                    if ($attrInheritance == 1) {
                        $attrValues = $this->_manageAttributeInheritance($item, $attrCode, $mapItem);
                    }
                    else {
                        $currentValue = $this->_manageAttributeForItem($item, $attrCode, $mapItem);
                        $this->_addAttributeToArray($currentValue, $attrValues);
                    }
                    Mage::log($attrValues);
                }
            }
        }
        return null;
    }

    /*
     * Check if a product has inherited product, get attribute value if so and cache them
     * Get attribute value from normal item if no inherited product
     */
    protected function  _manageAttributeInheritance($item, $attrCode, $mapItem) {
        $attrValues = array();
        $inheritanceType = $mapItem->getInheritanceType();
        if ($inheritanceType == 'from_child') {
            $hasChildren = false;
            if (!isset($this->_itemsCache['children'][$item->getId()])) { //If there are no children cached for the current item
                $childrenIds = $item->getTypeInstance()->getChildrenIds($item->getId(), false);
                $this->_itemsCache['children'][$item->getId()] = array();
                if (isset($childrenIds[0])) { //If there are children
                    $hasChildren = true;
                    $attrValues = $this->_doInheritanceAndCache($item, $childrenIds[0], $attrCode, $mapItem, 'children');
                }
            }
            else { //If there are children cached
                $children = $this->_itemsCache['children'][$item->getId()];
                if (!empty($children)) { //If there are children
                    $hasChildren = true;
                    $attrValues = $this->_doInheritance($children, $attrCode, $mapItem);
                }
            }
            if (!$hasChildren) {
                $currentValue = $this->_manageAttributeForItem($item, $attrCode, $mapItem); //If there are no children, we use the normal item to get attribute value
                $this->_addAttributeToArray($currentValue, $attrValues);
            }
        }
        elseif ($inheritanceType == 'from_parent') {

        }
        return $attrValues;
    }

    /*
     * Parse each inherited product to get attribute value
     */
    protected function  _doInheritance($items, $attrCode, $mapItem) {
        $attrValues = array();
        foreach ($items as $item) {
            $currentValue = $this->_manageAttributeForItem($item, $attrCode, $mapItem);
            $this->_addAttributeToArray($currentValue, $attrValues);
        }
        return $attrValues;
    }

    /*
     * Parse each inherited product to get attribute value and cache them
     */
    protected function  _doInheritanceAndCache($parent, $items, $attrCode, $mapItem, $cacheType){
        $attrValues = array();
        foreach ($items as $itemId) {
            //TODO USE COLLECTION
            $item = Mage::getModel('catalog/product')->load($itemId);
            $currentValue = $this->_manageAttributeForItem($item, $attrCode, $mapItem);
            $this->_addAttributeToArray($currentValue, $attrValues);
            $this->_itemsCache[$cacheType][$parent->getId()][$itemId] = $item; //Add the item to the cache
        }
        return $attrValues;
    }

    /*
     * Insert a new attribute value in the given array if the value is not empty and not already in the array
     */
    protected function  _addAttributeToArray($value, &$attrValues) {
        if (!empty($value) && !in_array($value, $attrValues)) {
            $attrValues[] = $value;
        }
    }

    /*
     * Manage attribute value for a given item.
     * Apply filters if necessary
     */
    protected function  _manageAttributeForItem($item, $attrCode, $mapItem) {
        //Callback method configuration for special attribute
        $attributeValueFilter = array(
            'url' => '_getProductUrl',
            'gross_price' => '_getGrossPrice',
            'qty' => '_getQuantity',
            'image_url' => '_getImageUrl'
        );
        if (($attributeMapping = $this->_getAttributeMapping($attrCode))) {
            $attrValue = $this->_manageAttributeMapping($attributeMapping, $item);
            if (!is_null($attrValue)) {
                return $attrValue;
            }
        }
        if (isset($attributeValueFilter[$attrCode])) {
            return $this->$attributeValueFilter[$attrCode]($item, $mapItem);
        }
        $attrValue = $item->getData($attrCode);
        if (isset($this->_attributeValues[$attrCode])) {
            if (isset($this->_attributeValues[$attrCode][$attrValue])) {
                return $this->_attributeValues[$attrCode][$attrValue];
            }
        }
        if (isset($this->_attributeTypes[$attrCode])) {
            if ($this->_attributeTypes[$attrCode] == 'multiselect') {
                $currentValues = explode(',', $attrValue);
                foreach ($currentValues as &$currentValue) {
                    if (isset($this->_attributeValues[$attrCode][$currentValue])) {
                        $currentValue = $this->_attributeValues[$attrCode][$currentValue];
                    }
                }
                $attrValue = implode(',', $currentValues);
                return $attrValue;
            }
        }
        return $attrValue;
    }

    protected function  _manageAttributeMapping($attributeMapping, $item) {
        $sourceAttributeCode = $attributeMapping->getSourceAttributeCode();
        if ($sourceAttributeCode == 'category') {
            $itemCategoriesIds = $item->getCategoryIds();
            $categoryId = array_shift($itemCategoriesIds);
            if (empty($categoryId)) {
                return null;
            }
            if ($attributeMapping->getCategoryType() == 'single') {
                if (isset($this->_categoryIds[$categoryId])) {
                    $attrValue = implode($this->getProfile()->getCategoryDelimiter(), $attributeMapping->getOptionValue($this->_categoryIds[$categoryId], $this->getProfile()->getStoreId()));
                    return $attrValue;
                }
            }
            else {
                $attrValue = $attributeMapping->getOptionValue($categoryId, $this->getProfile()->getStoreId());
                return $attrValue;
            }
        }
        else {
            $attrValue = $item->getData($sourceAttributeCode);
            if (!empty($attrValue)) {
                if ($this->_attributeTypes[$sourceAttributeCode] == 'multiselect') {
                    $attrValue = $attributeMapping->getOptionValue(explode(',', $attrValue), $this->getProfile()->getStoreId());
                    $attrValue = implode(',', $attrValue);

                } else {
                    $attrValue = $attributeMapping->getOptionValue($attrValue, $this->getProfile()->getStoreId());
                }
                return $attrValue;
            }
        }
        return null;
    }

    protected function  _getProductUrl($item, $mapItem) {
        $objProfile = $this->getProfile();
        if (version_compare(Mage::getVersion(), '1.13.0.0') >= 0) {
            $urlRewrite = Mage::getModel('enterprise_urlrewrite/url_rewrite')->getCollection()->addFieldToFilter('target_path', array('eq' => 'catalog/product/view/id/' . $item->getId()))->addFieldToFilter('is_system', array('eq' => 1));
            $attrValue = Mage::app()->getStore($objProfile->getStoreId())->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB) . $urlRewrite->getFirstItem()->getRequestPath();
        }
        else {
            $attrValue = $item->getProductUrl();
        }
        return $attrValue;
    }

    protected function  _getGrossPrice($item, $mapItem) {
        $objProfile = $this->getProfile();
        $attrValue = Mage::helper('tax')->getPrice($item, $item->getFinalPrice(), null, null, null, null, $objProfile->getStoreId(), null);
        return $attrValue;
    }

    protected function  _getQuantity($item, $mapItem) {
        $attrValue = intval(Mage::getModel('cataloginventory/stock_item')->loadByProduct($item)->getQty());
        return $attrValue;
    }

    protected function  _getImageUrl($item, $mapItem) {
        $attrValue = $item->getMediaConfig()->getMediaUrl($item->getData('image'));
        return $attrValue;
    }

    /**
     *
     *
     * @param $offsetProducts
     * @param $writer
     * @param $limitProducts
     * @param $filteredProductIds
     * @param $mapping
     * @return bool
     */
    public function _exportThread_OLD($offsetProducts, $writer, $limitProducts, $filteredProductIds, $mapping, $shippingAttrCodes)
    {
        $this->_cleanUpProcess();
        Mage::log($shippingAttrCodes);
        Mage::helper('mep/log')->debug('START Thread '.$offsetProducts, $this);

        $defaultStoreId = Mage_Catalog_Model_Abstract::DEFAULT_STORE_ID;
        /* @var $helperShipping Flagbit_MEP_Helper_Shipping */
        $helperShipping = Mage::helper('mep/shipping');

        $obj_profile = $this->getProfile();

        if($this->_limit !== null &&  $offsetProducts > 1){
            return false;
        }

        $dataRows = array();
        $rowCategories = array();
        $rowWebsites = array();
        $rowTierPrices = array();
        $rowGroupPrices = array();
        $rowMultiselects = array();
        $mediaGalery = array();
        $productsCount = 0;

        // prepare multi-store values and system columns values
        foreach ($this->_storeIdToCode as $storeId => &$storeCode) { // go through all stores

            if($storeId != $obj_profile->getStoreId() && $storeId != $defaultStoreId){
                continue;
            }

            Mage::helper('mep/log')->debug('START Export storeview '.$storeCode, $this);

            //set locale code to provide best sprintf support
            $localeInfo = $obj_profile->getProfileLocale();
            if ($localeInfo != null && strlen($localeInfo) > 0) {
                setlocale(LC_ALL, $localeInfo);
            } else {
                setlocale(LC_ALL, Mage::app()->getLocale()->getLocaleCode());
            }

            /* @var $collection Mage_Catalog_Model_Resource_Product_Collection */
            $collection = $this->_prepareEntityCollection(Mage::getResourceModel('catalog/product_collection'));
            $collection
                ->setStoreId($storeId)
                ->addStoreFilter($obj_profile->getStoreId())
                ->setPage($offsetProducts, $limitProducts);


            if(!empty($filteredProductIds)){
                $collection->addFieldToFilter("entity_id", array('in' => $filteredProductIds));
            }


            if ($collection->getCurPage() < $offsetProducts) {
                return false;
            }
            $collection->addUrlRewrite();
            $collection->load();

            if ($collection->count() == 0) {
                return false;
            }

            if ($defaultStoreId == $storeId) {
                $collection->addCategoryIds()->addWebsiteNamesToResult();

                // tier and group price data getting only once
                $rowTierPrices = $this->_prepareTierPrices($collection->getAllIds());
                $rowGroupPrices = $this->_prepareGroupPrices($collection->getAllIds());

                // getting media gallery data
                $mediaGalery = $this->_prepareMediaGallery($collection->getAllIds());
            }
            /* @var $item Mage_Catalog_Model_Product */
            foreach ($collection as $itemId => $item) { // go through all products
                $rowIsEmpty = true; // row is empty by default
                if ($item->getTypeId() == 'configurable') {
                    $simpleChilds = $item->getTypeInstance()->getUsedProducts();
                }

                #Mage::helper('mep/log')->debug('Export Product ('.$offsetProducts.') '.$item->getSku(), $this);

                foreach ($mapping->getItems() as $mapitem) {

                    foreach ($mapitem->getAttributeCodeAsArray() as $attrCode) {

                        $attrValue = $item->getData($attrCode);
                        if (($attrValue == null || ($attrCode == 'price' && $attrValue == 0)) && $item->getTypeId() == 'configurable') {
                            $attrValue = array();
                            $tmpAttrValue = array();
                            foreach ($simpleChilds as $simpleChild) {
                                $currentValue = $simpleChild->getData($attrCode);
                                if (!in_array($currentValue, $tmpAttrValue)) {
                                    $attrValue[] = array($simpleChild, $currentValue);
                                    $tmpAttrValue[] = $currentValue;
                                }
                            }
                        }
                        // shipping
                        if (array_key_exists($attrCode, $shippingAttrCodes)) {
                            $shipping_item = $shippingAttrCodes[$attrCode];
                            $attrValue = $helperShipping->emulateCheckout($item, $obj_profile->getStoreId(), $shipping_item);
                        }

                        // TODO dirty? Yes!
                        if ($attrCode == 'url') {
                            if (version_compare(Mage::getVersion(), '1.13.0.0') >= 0) {
                                $urlRewrite = Mage::getModel('enterprise_urlrewrite/url_rewrite')->getCollection()->addFieldToFilter('target_path', array('eq' => 'catalog/product/view/id/' . $item->getId()))->addFieldToFilter('is_system', array('eq' => 1));
                                $attrValue = Mage::app()->getStore($obj_profile->getStoreId())->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB) . $urlRewrite->getFirstItem()->getRequestPath();
                            }
                            else {
                                $attrValue = $item->getProductUrl();
                            }
                        }

                        if ($attrCode == 'gross_price') {
                            $attrValue = Mage::helper('tax')->getPrice($item, $item->getFinalPrice(), null, null, null,
                                null, $obj_profile->getStoreId(), null
                            );
                        }

                        if ($attrCode == 'fixed_value_format') {
                            $attrValue = $mapitem->getFormat();
                        }
                        if (!empty($this->_attributeValues[$attrCode])) {
                            if (!is_array($attrValue)) {
                                $attrValue = array(array($item, $attrValue));
                            }
                            if ($this->_attributeTypes[$attrCode] == 'multiselect') {
                                $tmpValue = array();
                                foreach ($attrValue as $value) {
                                    $value = explode(',', $value[1]);
                                    $tmpValue = array_merge(array_intersect_key(
                                        $this->_attributeValues[$attrCode],
                                        array_flip($value)
                                    ), $tmpValue);
                                }
                                $attrValue = $tmpValue;
                                $rowMultiselects[$itemId][$attrCode] = $attrValue;
                            } else if ($this->_attributeTypes[$attrCode] == 'select') {
                                $tmpValue = array();
                                foreach ($attrValue as $value) {
                                    $tmpValue[] = $value[0]->getAttributeText($attrCode);
                                }
                                $attrValue = implode($this->_configurable_delimiter, $tmpValue);
                            } else if (isset($this->_attributeValues[$attrCode][$attrValue[0][1]])) {
                                $tmpValue = array();
                                foreach ($attrValue as $value) {
                                    if (isset($this->_attributeValues[$attrCode][$value[1]])) {
                                        $tmpValue[] = $this->_attributeValues[$attrCode][$value[1]];
                                    }
                                }
                                $attrValue = implode($this->_configurable_delimiter, $tmpValue);
                            } else {
                                $attrValue = null;
                            }
                        }

                        // handle frontend Models
                        if (!empty($this->_attributeModels[$attrCode])
                            && $this->_attributeModels[$attrCode]->getFrontendModel()
                            && $this->_attributeModels[$attrCode]->getBackendType() != 'datetime'
                        ) {

                            $attrValue = $this->_frontend = Mage::getModel($this->_attributeModels[$attrCode]->getFrontendModel())->setAttribute($this->_attributeModels[$attrCode])->getValue($item);
                            if (isset($rowMultiselects[$itemId])) {
                                unset($rowMultiselects[$itemId]);
                            }
                        }

                        // value Mapping Attributes
                        $attributeMapping = $this->_getAttributeMapping($attrCode);
                        if ($attributeMapping
                            && $attributeMapping->getSourceAttributeCode() != 'category'
                            && $item->getData($attributeMapping->getSourceAttributeCode())
                        ) {
                            $attrValue = $item->getData($attributeMapping->getSourceAttributeCode());
                            if ($this->_attributeTypes[$attributeMapping->getSourceAttributeCode()] == 'multiselect') {
                                $attrValue = $attributeMapping->getOptionValue(explode(',', $attrValue), $obj_profile->getStoreId());
                                $rowMultiselects[$itemId][$attrCode] = $attrValue;

                            } else {
                                $attrValue = $attributeMapping->getOptionValue($attrValue, $obj_profile->getStoreId());
                            }
                            // value Mapping category
                        } elseif ($attributeMapping
                            && $attributeMapping->getSourceAttributeCode() == 'category'
                        ) {

                            $rrowCategories = $item->getCategoryIds();
                            $categoryId = array_shift($rrowCategories);

                            if (isset($this->_categoryIds[$categoryId])) {

                                if($attributeMapping->getCategoryType() == 'single'){
                                    $attrValue = implode(
                                        $this->getProfile()->getCategoryDelimiter(),
                                        $attributeMapping->getOptionValue($this->_categoryIds[$categoryId], $obj_profile->getStoreId())
                                    );
                                }else{
                                    $attrValue = $attributeMapping->getOptionValue($categoryId, $obj_profile->getStoreId());
                                }
                            }
                        }


                        // do not save value same as default or not existent
                        if ($storeId != $defaultStoreId
                            && isset($dataRows[$itemId][$defaultStoreId][$attrCode])
                            && $dataRows[$itemId][$defaultStoreId][$attrCode] == $attrValue
                        ) {
                            $attrValue = null;
                        }
                        if (is_scalar($attrValue)) {
                            if ($attrCode == 'price') {
                                $attrValue = number_format($attrValue, 2, ',', '.');
                            }
                            $dataRows[$itemId][$storeId][$attrCode] = $attrValue;
                            $rowIsEmpty = false;
                        }
                        else {
                            if (is_array($attrValue) && $attrCode == 'price') {
                                $tmpValue = array();
                                foreach ($attrValue as $value) {
                                    $currentValue = number_format($value[1], 2, ',', '.');
                                    if (!in_array($currentValue, $tmpValue)) {
                                        $tmpValue[] = $currentValue;
                                    }
                                }
                                $attrValue = implode($this->_configurable_delimiter, $tmpValue);
                                $dataRows[$itemId][$defaultStoreId][$attrCode] = $attrValue;
                            }
                        }
                    }
                }

                if ($rowIsEmpty) { // remove empty rows
                    unset($dataRows[$itemId][$storeId]);
                } else {
                    $attrSetId = $item->getAttributeSetId();
                    $dataRows[$itemId][$storeId][self::COL_STORE] = $storeCode;
                    $dataRows[$itemId][$storeId][self::COL_ATTR_SET] = $this->_attrSetIdToName[$attrSetId];
                    $dataRows[$itemId][$storeId][self::COL_TYPE] = $item->getTypeId();

                    if ($defaultStoreId == $storeId) {
                        $rowWebsites[$itemId] = $item->getWebsites();
                        $rowCategories[$itemId] = $item->getCategoryIds();
                    }
                }
                $item = null;
            }
            $collection->clear();
        }
        if ($collection->getCurPage() < $offsetProducts) {
            return false;
        }
        // remove unused categories
        $allCategoriesIds = array_merge(array_keys($this->_categories), array_keys($this->_rootCategories));
        foreach ($rowCategories as &$categories) {
            $categories = array_intersect($categories, $allCategoriesIds);
        }

        // prepare catalog inventory information
        $productIds = array_keys($dataRows);
        $stockItemRows = $this->_prepareCatalogInventory($productIds);

        // prepare links information
        $linksRows = $this->_prepareLinks($productIds);
        $linkIdColPrefix = array(
            Mage_Catalog_Model_Product_Link::LINK_TYPE_RELATED => '_links_related_',
            Mage_Catalog_Model_Product_Link::LINK_TYPE_UPSELL => '_links_upsell_',
            Mage_Catalog_Model_Product_Link::LINK_TYPE_CROSSSELL => '_links_crosssell_',
            Mage_Catalog_Model_Product_Link::LINK_TYPE_GROUPED => '_associated_'
        );


        foreach ($dataRows as $productId => &$productData) {
            foreach ($productData as $storeId => &$dataRow) {
                if ($defaultStoreId != $storeId) {
                    $dataRow[self::COL_SKU] = null;
                    $dataRow[self::COL_ATTR_SET] = null;
                    $dataRow[self::COL_TYPE] = null;
                } else {
                    $dataRow[self::COL_STORE] = null;
                    $dataRow += $stockItemRows[$productId];
                }
                $this->_updateDataWithCategoryColumns($dataRow, $rowCategories, $productId);
                if ($rowWebsites[$productId]) {
                    $dataRow['_product_websites'] = $this->_websiteIdToCode[array_shift($rowWebsites[$productId])];
                }
                if (!empty($rowTierPrices[$productId])) {
                    $dataRow = array_merge($dataRow, array_shift($rowTierPrices[$productId]));
                }
                if (!empty($rowGroupPrices[$productId])) {
                    $dataRow = array_merge($dataRow, array_shift($rowGroupPrices[$productId]));
                }
                if (!empty($mediaGalery[$productId])) {
                    $dataRow = array_merge($dataRow, array_shift($mediaGalery[$productId]));
                }
                foreach ($linkIdColPrefix as $linkId => &$colPrefix) {
                    if (!empty($linksRows[$productId][$linkId])) {
                        $linkData = array_shift($linksRows[$productId][$linkId]);
                        $dataRow[$colPrefix . 'position'] = $linkData['position'];
                        $dataRow[$colPrefix . 'sku'] = $linkData['sku'];

                        if (null !== $linkData['default_qty']) {
                            $dataRow[$colPrefix . 'default_qty'] = $linkData['default_qty'];
                        }
                    }
                }
                if (!empty($customOptionsData[$productId])) {
                    $dataRow = array_merge($dataRow, array_shift($customOptionsData[$productId]));
                }
                if (!empty($configurableData[$productId])) {
                    $dataRow = array_merge($dataRow, array_shift($configurableData[$productId]));
                }
                if (!empty($rowMultiselects[$productId])) {
                    foreach ($rowMultiselects[$productId] as $attrKey => $attrVal) {
                        if (!empty($rowMultiselects[$productId][$attrKey])) {
                            $dataRow[$attrKey] = array_shift($rowMultiselects[$productId][$attrKey]);
                        }
                    }
                }


                //INSERT _category mapping
                foreach ($mapping->getItems() as $mapitem) {
                    $attrCode = $mapitem->getAttributeCode();
                    if ($attrCode == '_category') {
                        if (isset($dataRow['_category'])) {
                            $dataRow[$attrCode] = $dataRow['_category'];
                        }
                    }
                    if ($attrCode == 'image_url') {
                        if (isset($dataRow['_media_image'])) {
                            $dataRow[$attrCode] = Mage::app()->getStore($obj_profile->getStoreId())->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA) . 'catalog/product' . $dataRow['_media_image'];
                        }
                    }
                }

                // store default store values;
                if ($defaultStoreId == $storeId) {
                    $defaultDataRow = $dataRow;
                }

            }
            if($offsetProducts != 1){
                $writer->setHeaderIsDisabled();
            }
            $dataRow = array_merge($defaultDataRow, array_filter( $dataRow, create_function('$value', 'return empty($value) ? 0 : 1;')));
            $productsCount++;
            $writer->writeRow($dataRow);
        }
        Mage::helper('mep/log')->debug('END Thread '.$offsetProducts.' ('.$productsCount.' Products)', $this);

        return true;
    }


    /**
     * Clean up already loaded attribute collection.
     *
     * @param Mage_Eav_Model_Resource_Entity_Attribute_Collection $collection
     * @return Mage_Eav_Model_Resource_Entity_Attribute_Collection
     */
    public function filterAttributeCollection(Mage_Eav_Model_Resource_Entity_Attribute_Collection $collection)
    {
        $validTypes = array_keys($this->_productTypeModels);

        foreach (parent::filterAttributeCollection($collection) as $attribute) {
            $attrApplyTo = $attribute->getApplyTo();
            $attrApplyTo = $attrApplyTo ? array_intersect($attrApplyTo, $validTypes) : $validTypes;

            if ($attrApplyTo) {
                foreach ($attrApplyTo as $productType) { // override attributes by its product type model
                    if ($this->_productTypeModels[$productType]->overrideAttribute($attribute)) {
                        break;
                    }
                }
            } else { // remove attributes of not-supported product types
                $collection->removeItemByKey($attribute->getId());
            }
        }
        return $collection;
    }

    /**
     * Entity attributes collection getter.
     *
     * @return Mage_Catalog_Model_Resource_Eav_Mysql4_Product_Attribute_Collection
     */
    public function getAttributeCollection()
    {
        return Mage::getResourceModel('catalog/product_attribute_collection');
    }

    /**
     * EAV entity type code getter.
     *
     * @return string
     */
    public function getEntityTypeCode()
    {
        return 'catalog_product';
    }

    /**
     * Initialize attribute option values and types.
     *
     * @return Mage_ImportExport_Model_Export_Entity_Product
     */
    protected function _initAttributes()
    {
        foreach ($this->getAttributeCollection() as $attribute) {
            $this->_attributeValues[$attribute->getAttributeCode()] = $this->getAttributeOptions($attribute);
            $this->_attributeTypes[$attribute->getAttributeCode()] =
                Mage_ImportExport_Model_Import::getAttributeType($attribute);
            $this->_attributeModels[$attribute->getAttributeCode()] = $attribute;
        }
        return $this;
    }

    /**
     * @return Flagbit_MEP_Model_Profile|Mage_Core_Model_Abstract|null
     */
    public function getProfile()
    {
        if ($this->_profile == null && $this->hasProfileId()) {
            $this->_profile = Mage::getModel('mep/profile')->load($this->getProfileId());
        }
        return $this->_profile;
    }

    /**
     * @return bool
     */
    public function hasProfileId()
    {
        return array_key_exists('id', $this->_parameters);
    }

    /**
     * @return int
     */
    public function getProfileId()
    {
        return (int)$this->_parameters['id'];
    }

}
