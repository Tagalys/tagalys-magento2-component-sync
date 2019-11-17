<?php

namespace Tagalys\Sync\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;

class UpgradeSchema implements UpgradeSchemaInterface
{
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context){
        $installer = $setup;
        $installer->startSetup();

        if (!$context->getVersion()) {
        //no previous version found, installation, InstallSchema was just executed
        //be careful, since everything below is true for installation !
        }

    if (version_compare($context->getVersion(), '1.1.1') < 0) {
        $installer->getConnection()->addIndex(
            $installer->getTable('tagalys_queue'),
            $installer->getIdxName(
                $installer->getTable('tagalys_queue'),
                ['product_id'],
                \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE
            ),
            ['product_id'],
            \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE
        );
    }

    if (version_compare($context->getVersion(), '1.1.0') < 0) {
      //code to upgrade to 1.1.0 -> Magento platform rendering mode
      $categoryTableName = $installer->getTable('tagalys_category');
      if ($installer->getConnection()->isTableExists($categoryTableName) != true) {
          $categoryTable = $installer->getConnection()
              ->newTable($categoryTableName)
              ->addColumn(
                  'id',
                  Table::TYPE_INTEGER,
                  11,
                  [
                      'identity' => true,
                      'unsigned' => true,
                      'nullable' => false,
                      'primary' => true
                  ],
                  'ID'
              )
              ->addColumn(
                  'category_id',
                  Table::TYPE_INTEGER,
                  50,
                  [
                      'nullable' => false
                  ],
                  'Category ID'
              )
              ->addColumn(
                  'store_id',
                  Table::TYPE_INTEGER,
                  50,
                  [
                      'nullable' => false
                  ],
                  'Store ID'
              )
              ->addColumn(
                  'positions_synced_at',
                  Table::TYPE_DATETIME,
                  null,
                  [],
                  'Positions last synced at'
              )
              ->addColumn(
                  'positions_sync_required',
                  Table::TYPE_BOOLEAN,
                  null,
                  [
                      'nullable' => false,
                      'default' => '0'
                  ],
                  'Positions synced required?'
              )
              ->addColumn(
                  'marked_for_deletion',
                  Table::TYPE_BOOLEAN,
                  null,
                  [
                      'nullable' => false,
                      'default' => '0'
                  ],
                  'Marked for deletion'
              )
              ->addColumn(
                  'status',
                  Table::TYPE_TEXT,
                  255,
                  [
                      'nullable' => false,
                      'default' => ''
                  ],
                  'Status'
              )
              ->setComment('Tagalys Categories Table')
              ->setOption('type', 'InnoDB')
              ->setOption('charset', 'utf8');
          $categoryTable->addIndex(
              $installer->getIdxName('tagalys_category', ['store_id', 'category_id']),
              ['store_id', 'category_id']
          );
          $installer->getConnection()->createTable($categoryTable);
      }
    }

        $installer->endSetup();
    }
}
