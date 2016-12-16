<?php
/**
 * This file is part of the Flagbit_MEP project.
 *
 * Flagbit_CronCli is free software; you can redistribute it and/or
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
 * @author Kyrylo Kostiukov <kyrylo.kostiukov@flagbit.de>
 * @copyright 2012 Flagbit GmbH & Co. KG (http://www.flagbit.de). All rights served.
 * @license http://opensource.org/licenses/gpl-3.0 GNU General Public License, version 3 (GPLv3)
 * @version 0.1.0
 * @since 0.2.3
 */
$installer = $this;
$installer->startSetup();

$installer->run("
ALTER TABLE `{$this->getTable('mep/google_mapping')}` ADD COLUMN `store_id` int;
");

$installer->run("
ALTER TABLE `{$this->getTable('mep/google_mapping')}` drop index category_id;
");

$installer->run("
ALTER TABLE `{$this->getTable('mep/google_mapping')}` add unique `category_store_unq_idx` (category_id, store_id);
");

$installer->run("
CREATE TABLE `mep_google_store_language` (
  `store_id` int(11) NOT NULL,
  `language` char(8) NOT NULL,
  PRIMARY KEY (`store_id`)
);");

$installer->endSetup();
