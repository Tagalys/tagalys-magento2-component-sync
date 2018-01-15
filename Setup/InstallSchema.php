<?php
 
namespace Tagalys\Sync\Setup;
 
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;
 
class InstallSchema implements InstallSchemaInterface
{
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();
 
        $configTableName = $installer->getTable('tagalys_config');
        if ($installer->getConnection()->isTableExists($configTableName) != true) {
            $configTable = $installer->getConnection()
                ->newTable($configTableName)
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
                    'path',
                    Table::TYPE_TEXT,
                    255,
                    [
                        'nullable' => false,
                        'default' => ''
                    ],
                    'Config Path'
                )
                ->addColumn(
                    'value',
                    Table::TYPE_TEXT,
                    null,
                    [
                        'nullable' => false,
                        'default' => ''
                    ],
                    'Config Value'
                )
                ->setComment('Tagalys Configuration Table')
                ->setOption('type', 'InnoDB')
                ->setOption('charset', 'utf8');
            $installer->getConnection()->createTable($configTable);
        }

        $queueTableName = $installer->getTable('tagalys_queue');
        if ($installer->getConnection()->isTableExists($queueTableName) != true) {
            $queueTable = $installer->getConnection()
                ->newTable($queueTableName)
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
                    'product_id',
                    Table::TYPE_INTEGER,
                    11,
                    [
                        'nullable' => false,
                        'default' => '0'
                    ],
                    'Product ID'
                )
                ->setComment('Tagalys Sync Queue Table')
                ->setOption('type', 'InnoDB')
                ->setOption('charset', 'utf8');
            $installer->getConnection()->createTable($queueTable);
        }
 
        $installer->endSetup();
    }
}