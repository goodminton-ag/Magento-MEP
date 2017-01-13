<?php
/**
 * This file is part of the Flagbit MEP project.
 *
 * Flagbit MEP is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 3 as
 * published by the Free Software Foundation.
 *
 * This script is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * PHP version 5
 *
 * @category Flagbit_MEP
 * @package Flagbit_MEP
 * @author Damian Luszczymak <damian.luszczymak@flagbit.de>
 * @author Karl Spies <karl.spies@flagbit.de>
 * @copyright 2012 Flagbit GmbH & Co. KG (http://www.flagbit.de). All rights served.
 * @license http://opensource.org/licenses/gpl-3.0 GNU General Public License, version 3 (GPLv3)
 * @version 0.1.0
 * @since 0.1.0
 */
class Flagbit_MEP_Model_Export_Adapter_Twig extends Mage_ImportExport_Model_Export_Adapter_Abstract
{
    /**
     * Field headerrow.
     *
     * @var boolean
     */
    protected $_headerRow = true;

    protected $_headerDisabled = false;

    /**
     * @var Varien_File_Csv
     */
    protected $_csvWriter;

    /**
     * @var Twig_Environment
     */
    protected $_twig;

    protected $_encoding;

    private $_configurable_delimiter;

    protected $_delimiter;

    protected $_enclosure;

    /**
     * Object destructor.
     *
     * @return void
     */
    public function __destruct()
    {
        if (is_resource($this->_fileHandler)) {
            fclose($this->_fileHandler);
        }
    }


    /**
     * MIME-type for 'Content-Type' header.
     *
     * @return string
     */
    public function getContentType()
    {
        return 'text/csv';
    }

    /**
     * Return file extension for downloading.
     *
     * @return string
     */
    public function getFileExtension()
    {
        return 'csv';
    }


    /**
     * Get contents of export file.
     *
     * @return string
     */
    public function getContents()
    {
        $result = $this->_twig->render('footer', array_combine(array_keys($this->_headerCols), array_keys($this->_headerCols)));
        fwrite($this->_fileHandler, trim($result).PHP_EOL);
        return file_get_contents($this->_destination);
    }

    /**
     * @param boolean $headerrow
     * @return \Flagbit_MEP_Model_Export_Adapter_Csv
     */
    public function setHeaderRow($headerrow)
    {
        $this->_headerRow = $headerrow;
        return $this;
    }

    /**
     * @return Mage_ImportExport_Model_Export_Adapter_Abstract|void
     */
    public function _init()
    {
        parent::_init();
        $this->_fileHandler = fopen($this->_destination, 'a');
        $this->_twig = new Twig_Environment($this->_getTwigLoader(), array(
            'cache' => Mage::getBaseDir('cache'),
            'autoescape' => false,
        ));
        // enable sandbox

        $_policy = Mage::getModel('mep/twig_sandbox_policy');
        $sandbox = new Twig_Extension_Sandbox($_policy, true);
        $this->_twig->addExtension($sandbox);

        // Event to offer the possibility to add Twig Modules
        Mage::dispatchEvent('mep_export_adapter_twig_init', array(
            'adapter' => $this,
            'twig' => $this->_twig,
            'policy' => $_policy
        ));

    }

    /**
     * @return Flagbit_MEP_Model_Twig_Loader
     */
    protected function _getTwigLoader()
    {
        return Mage::getSingleton('mep/twig_loader');
    }


    /**
     * @param string $delimiter
     * @return \Flagbit_MEP_Model_Export_Adapter_Csv
     */
    public function setDelimiter($delimiter)
    {
        $this->_delimiter = str_replace('\t', chr(9), $delimiter);
        return $this;
    }

    public function setConfigurableDelimiter($delimiter) {
        $this->_configurable_delimiter = $delimiter;
    }

    public function getConfigurableDelimiter() {
        return $this->_configurable_delimiter;
    }

    /**
     * @param string $template
     * @param string $type
     * @return \Flagbit_MEP_Model_Export_Adapter_Csv
     */
    public function setTwigTemplate($template, $type)
    {
        $template = str_replace('\t', chr(9), $template);
        $this->_getTwigLoader()->setTemplate($type, $template);
        return $this;
    }

    public function setEncoding($encoding) {
        $this->_encoding = $encoding;
    }

    /**
     * @param string $enclosure
     * @return \Flagbit_MEP_Model_Export_Adapter_Csv
     */
    public function setEnclosure($enclosure)
    {
        $this->_enclosure = $enclosure;
        return $this;
    }

    public function setHeaderIsDisabled()
    {
        $this->_headerDisabled = true;
        return $this;
    }

    /**
     * Write row data to source file.
     *
     * @param array $rowData
     * @throws Exception
     * @return Mage_ImportExport_Model_Export_Adapter_Abstract
     */
    public function writeRow(array $rowData)
    {
        if ($this->_headerDisabled === false && null === $this->_headerCols) {
            $this->setHeaderCols(array_keys($rowData));
        }
        $twigDataRow = array_map(array($this, 'cleanElement'), $rowData);
        $result = $this->_twig->render('content', $twigDataRow);

        $result = Mage::helper('mep/encoding')->fixUTF8($result);

        if (!empty($this->_encoding) && $this->_encoding != 'UTF-8') {
            $result = iconv ( "UTF-8", $this->_encoding, $result );
        }

        fwrite($this->_fileHandler, trim($result).PHP_EOL);
        return $this;
    }

    /**
     * Set column names.
     *
     * @param array $headerCols
     * @throws Exception
     * @return Mage_ImportExport_Model_Export_Adapter_Abstract
     */
    public function setHeaderCols(array $headerCols)
    {
        if (null !== $this->_headerCols) {
            Mage::throwException(Mage::helper('importexport')->__('Header column names already set'));
        }
        if ($headerCols) {
            foreach ($headerCols as $colName) {
                $this->_headerCols[$colName] = false;
            }

            $result = $this->_twig->render('header', array_combine(array_keys($this->_headerCols), array_keys($this->_headerCols)));
            fwrite($this->_fileHandler, trim($result).PHP_EOL);
        }
        return $this;
    }

    /**
     * clean CSV Data Element
     *
     * @param $element
     * @return string
     */
    public function cleanElement($element)
    {
        if (is_array($element)) {
            foreach ($element as $key => $value) {
                $element[$key] = $this->cleanElement($value);
            }
            return $element;
        }
        if(substr($element,0,2) == 'a:') {
            return $element;
        }
        //$element = htmlentities($element, ENT_QUOTES | ENT_IGNORE);
        $element = Mage::helper('mep/encoding')->decodeEntities($element);
        $element = trim($element);
        $element = str_replace(array($this->_delimiter, $this->_enclosure), '', $element);
        $element = str_replace(array("\r\n", "\r", "\n"), '', $element);
        // remove trademark sign (hex value 99)
        $element = preg_replace('/[\x99]/', '', $element);
        $element = utf8_encode($element);
        return $element;
    }
}
