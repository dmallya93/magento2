<?php
/**
 * Functional-test harness for Magento_Tag (milestone 1, data layer).
 *
 * Bootstraps the real installed Magento application (app/bootstrap.php + ObjectManager) and executes a
 * single operation against the real models / resource models / collections / helper and the real DB.
 *
 * Usage: php tag_harness.php <operation> '<json-args>'
 * Output: a single line "@@RESULT@@<json>" on stdout. Exit code 0 on success, 2 on an exception thrown
 * by the application (the JSON then carries "error" and "exception_class").
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Registry;

$root = getenv('MAGENTO_ROOT') ?: dirname(__DIR__, 4);
require $root . '/app/bootstrap.php';

$op = $argv[1] ?? '';
$args = json_decode($argv[2] ?? '{}', true) ?: [];

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$area = $args['area'] ?? 'adminhtml';
$om->get(State::class)->setAreaCode($area);
$om->configure($om->get(\Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load($area));

/** @var \Magento\Tag\Model\OperationIdGenerator $opIdGen */
$opIdGen = $om->get(\Magento\Tag\Model\OperationIdGenerator::class);
$operationId = $opIdGen->startOperation();

$conn = $om->get(ResourceConnection::class)->getConnection();
$t = fn(string $name) => $om->get(ResourceConnection::class)->getTableName($name);

function out(array $data, int $code = 0): void
{
    echo "@@RESULT@@" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
    exit($code);
}

function tagRow($conn, $t, $id)
{
    $row = $conn->fetchRow($conn->select()->from($t('tag'))->where('tag_id = ?', $id));
    return $row ?: null;
}

function relRows($conn, $t, $tagId)
{
    return $conn->fetchAll(
        $conn->select()->from($t('tag_relation'))->where('tag_id = ?', $tagId)->order('tag_relation_id')
    );
}

function collectionItems($collection, array $fields): array
{
    $items = [];
    foreach ($collection as $item) {
        $row = [];
        foreach ($fields as $f) {
            $v = $item->getData($f);
            $row[$f] = is_object($v) ? (string)$v : $v;
        }
        $items[] = $row;
    }
    return $items;
}

$secure = function () use ($om) {
    $reg = $om->get(Registry::class);
    if (!$reg->registry('isSecureArea')) {
        $reg->register('isSecureArea', true);
    }
};

try {
    $result = [];
    switch ($op) {
        // ---------------------------------------------------------------- fixtures
        case 'setup_fixtures':
            $ns = $args['ns'];
            $secure();
            // second store view in the default website/group (namespaced code)
            $storeCode = substr('ft_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($ns)) . '_s2', 0, 32);
            $store2 = $conn->fetchOne($conn->select()->from($t('store'), 'store_id')->where('code = ?', $storeCode));
            if (!$store2) {
                $conn->insert($t('store'), [
                    'code' => $storeCode, 'website_id' => 1, 'group_id' => 1,
                    'name' => 'FT ' . $ns . ' store 2', 'sort_order' => 99, 'is_active' => 1,
                ]);
                $store2 = $conn->lastInsertId($t('store'));
            }
            // customers via the customer repository
            $customerRepo = $om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
            $customerFactory = $om->get(\Magento\Customer\Api\Data\CustomerInterfaceFactory::class);
            $customers = [];
            foreach ([1, 2, 3, 4] as $i) {
                $email = strtolower($ns) . "_c{$i}@example.com";
                try {
                    $customers[] = (int)$customerRepo->get($email, 1)->getId();
                } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                    $c = $customerFactory->create();
                    $c->setEmail($email)->setFirstname('Ft' . $i)->setLastname('Tagger' . $i)
                        ->setWebsiteId(1)->setGroupId(1)->setStoreId(1);
                    $customers[] = (int)$customerRepo->save($c)->getId();
                }
            }
            // products via the product repository
            $productRepo = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
            $productFactory = $om->get(\Magento\Catalog\Api\Data\ProductInterfaceFactory::class);
            $products = [];
            foreach ([1, 2, 3, 4] as $i) {
                $sku = $ns . "_p{$i}";
                try {
                    $products[] = (int)$productRepo->get($sku)->getId();
                } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                    $p = $productFactory->create();
                    $p->setSku($sku)->setName('FT ' . $ns . ' Product ' . $i)->setAttributeSetId(4)
                        ->setTypeId('simple')->setPrice(10 + $i)->setStatus(1)->setVisibility(4)
                        ->setWebsiteIds([1])->setUrlKey(strtolower($sku) . '-url')
                        ->setStockData(['qty' => 100, 'is_in_stock' => 1, 'use_config_manage_stock' => 1]);
                    $products[] = (int)$productRepo->save($p)->getId();
                }
            }
            $result = ['store2' => (int)$store2, 'store1' => 1, 'customers' => $customers, 'products' => $products];
            break;

        case 'teardown_fixtures':
            $ns = $args['ns'];
            $secure();
            $deletedTags = $conn->delete($t('tag'), ['name LIKE ?' => $ns . '%']);
            $productRepo = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
            $ids = $conn->fetchCol(
                $conn->select()->from($t('catalog_product_entity'), 'sku')->where('sku LIKE ?', $ns . '%')
            );
            foreach ($ids as $sku) {
                try {
                    $productRepo->deleteById($sku);
                } catch (\Throwable $e) {
                    $conn->delete($t('catalog_product_entity'), ['sku = ?' => $sku]);
                }
            }
            $customerRepo = $om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
            $cids = $conn->fetchCol(
                $conn->select()->from($t('customer_entity'), 'entity_id')
                    ->where('email LIKE ?', strtolower($ns) . '%')
            );
            foreach ($cids as $cid) {
                try {
                    $customerRepo->deleteById($cid);
                } catch (\Throwable $e) {
                    $conn->delete($t('customer_entity'), ['entity_id = ?' => $cid]);
                }
            }
            $storeCode = substr('ft_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($ns)) . '_s2', 0, 32);
            $deletedStores = $conn->delete($t('store'), ['code = ?' => $storeCode]);
            $result = [
                'deleted_tags' => $deletedTags, 'deleted_products' => count($ids),
                'deleted_customers' => count($cids), 'deleted_stores' => $deletedStores,
            ];
            break;

        case 'customer_create':
            $customerRepo = $om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
            $c = $om->get(\Magento\Customer\Api\Data\CustomerInterfaceFactory::class)->create();
            $c->setEmail($args['email'])->setFirstname('Ftx')->setLastname('Tmp')->setWebsiteId(1)->setGroupId(1);
            $result = ['customer_id' => (int)$customerRepo->save($c)->getId()];
            break;

        case 'customer_delete':
            $secure();
            $om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)->deleteById((int)$args['id']);
            $result = ['deleted' => (int)$args['id']];
            break;

        case 'product_delete':
            $secure();
            $repo = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
            $repo->delete($repo->getById((int)$args['id']));
            $result = ['deleted' => (int)$args['id']];
            break;

        // ---------------------------------------------------------------- tag model
        case 'tag_save':
            /** @var \Magento\Tag\Model\Tag $tag */
            $tag = $om->create(\Magento\Tag\Model\Tag::class);
            if (!empty($args['id'])) {
                $tag->load($args['id']);
            }
            foreach (($args['data'] ?? []) as $k => $v) {
                $tag->setData($k, $v);
            }
            if (!empty($args['simulate_race'])) {
                // Simulate the M1 concurrent-submission race: the competing request's uniqueness check already
                // passed, so this save does not see the other row. Done by clearing the resource's unique-field
                // list for this one save only; everything else (the INSERT and _afterSave) is the real code.
                $res = $tag->getResource();
                $ref = new \ReflectionProperty(\Magento\Framework\Model\ResourceModel\Db\AbstractDb::class, '_uniqueFields');
                $ref->setAccessible(true);
                $ref->setValue($res, []);
            }
            $tag->save();
            $result = ['tag_id' => (int)$tag->getId(), 'model' => $tag->getData(), 'row' => tagRow($conn, $t, $tag->getId())];
            if (!empty($args['simulate_race'])) {
                $result['model'] = array_intersect_key($tag->getData(), array_flip(['tag_id', 'name', 'status']));
            }
            break;

        case 'tag_load':
            $tag = $om->create(\Magento\Tag\Model\Tag::class);
            if (isset($args['store_id'])) {
                $tag->setStoreId($args['store_id']);
            }
            if (!empty($args['add_base_popularity'])) {
                $tag->setAddBasePopularity(true);
            }
            $tag->load($args['id']);
            $result = [
                'tag_id' => $tag->getId() ? (int)$tag->getId() : null,
                'data' => $tag->getData(),
                'is_available_in_store' => isset($args['check_store']) ? $tag->isAvailableInStore($args['check_store']) : null,
            ];
            break;

        case 'tag_load_by_name':
            $tag = $om->create(\Magento\Tag\Model\Tag::class);
            $tag->loadByName($args['name']);
            $result = ['tag_id' => $tag->getId() ? (int)$tag->getId() : null, 'data' => $tag->getData()];
            break;

        case 'tag_delete':
            $tag = $om->create(\Magento\Tag\Model\Tag::class)->load($args['id']);
            $tag->delete();
            $result = ['deleted' => (int)$args['id'], 'row' => tagRow($conn, $t, $args['id'])];
            break;

        case 'save_relation':
            $tag = $om->create(\Magento\Tag\Model\Tag::class)->load($args['tag_id']);
            $code = $tag->saveRelation($args['product_id'], $args['customer_id'], $args['store_id']);
            $result = [
                'result' => $code,
                'tag_row' => tagRow($conn, $t, $args['tag_id']),
                'relations' => relRows($conn, $t, $args['tag_id']),
            ];
            break;

        case 'related_product_ids':
            $tag = $om->create(\Magento\Tag\Model\Tag::class)->load($args['tag_id']);
            if (isset($args['store_id'])) {
                $tag->setStoreId($args['store_id']);
            }
            if (array_key_exists('status_filter', $args)) {
                $tag->setStatusFilter($args['status_filter']);
            }
            $ids = array_map('intval', $tag->getRelatedProductIds());
            sort($ids);
            $result = ['product_ids' => $ids];
            break;

        case 'aggregate':
            $tag = $om->create(\Magento\Tag\Model\Tag::class)->load($args['tag_id']);
            if (isset($args['store'])) {
                $tag->setStore($args['store']);
            }
            if (isset($args['base_popularity'])) {
                $tag->setBasePopularity($args['base_popularity']);
            }
            $tag->aggregate();
            $result = ['summary' => $conn->fetchAll(
                $conn->select()->from($t('tag_summary'))->where('tag_id = ?', $args['tag_id'])->order('store_id')
            )];
            break;

        // ---------------------------------------------------------------- relation model
        case 'relation_save':
            $rel = $om->create(\Magento\Tag\Model\TagRelation::class);
            foreach ($args['data'] as $k => $v) {
                $rel->setData($k, $v);
            }
            $rel->save();
            $result = ['tag_relation_id' => (int)$rel->getId(), 'row' => $conn->fetchRow(
                $conn->select()->from($t('tag_relation'))->where('tag_relation_id = ?', $rel->getId())
            )];
            break;

        case 'relation_delete':
            $rel = $om->create(\Magento\Tag\Model\TagRelation::class)->load($args['id']);
            $rel->delete();
            $result = ['deleted' => (int)$args['id']];
            break;

        case 'relation_load_by_tag_customer':
            $rel = $om->create(\Magento\Tag\Model\TagRelation::class);
            $rel->loadByTagCustomer($args['product_id'] ?? null, $args['tag_id'], $args['customer_id'], $args['store_id'] ?? null);
            $result = ['tag_relation_id' => $rel->getId() ? (int)$rel->getId() : null, 'data' => $rel->getData()];
            break;

        case 'relation_product_ids':
            $rel = $om->create(\Magento\Tag\Model\TagRelation::class);
            $rel->setTagId($args['tag_id']);
            $rel->setCustomerId($args['customer_id'] ?? null);
            if (isset($args['store_id'])) {
                $rel->setStoreId($args['store_id']);
            }
            if (array_key_exists('status_filter', $args)) {
                $rel->setStatusFilter($args['status_filter']);
            }
            $ids = array_map('intval', $rel->getProductIds());
            sort($ids);
            $result = ['product_ids' => $ids];
            break;

        case 'relation_deactivate':
            $rel = $om->create(\Magento\Tag\Model\TagRelation::class);
            $rel->setTagId($args['tag_id'])->setCustomerId($args['customer_id']);
            $rel->deactivate();
            $result = ['relations' => relRows($conn, $t, $args['tag_id'])];
            break;

        case 'add_relations':
            $tag = $om->create(\Magento\Tag\Model\Tag::class)->load($args['tag_id']);
            $tag->setStore($args['store']);
            $rel = $om->create(\Magento\Tag\Model\TagRelation::class);
            $rel->addRelations($tag, $args['product_ids']);
            $result = [
                'inserted' => array_map('intval', (array)$rel->getData('inserted_product_ids')),
                'deleted' => array_map('intval', (array)$rel->getData('deleted_product_ids')),
                'relations' => relRows($conn, $t, $args['tag_id']),
            ];
            break;

        case 'tag_save_assigned_products':
            $tag = $om->create(\Magento\Tag\Model\Tag::class)->load($args['tag_id']);
            $tag->setStore($args['store']);
            $tag->setData('tag_assigned_products', $args['product_ids']);
            $tag->save();
            $result = ['relations' => relRows($conn, $t, $args['tag_id'])];
            break;

        case 'event_payload':
            // Dispatch a real save through the event manager and capture the payload keys seen by observers.
            $cls = $args['model'] === 'relation' ? \Magento\Tag\Model\TagRelation::class : \Magento\Tag\Model\Tag::class;
            $model = $om->create($cls);
            $m = new \ReflectionMethod($model, '_getEventData');
            $m->setAccessible(true);
            $payload = $m->invoke($model);
            $pp = new \ReflectionProperty($model, '_eventPrefix');
            $pp->setAccessible(true);
            $result = ['event_prefix' => $pp->getValue($model), 'keys' => array_keys($payload),
                'object_is_model' => isset($payload['object']) && $payload['object'] === $model];
            break;

        // ---------------------------------------------------------------- collections
        case 'tag_collection':
            /** @var \Magento\Tag\Model\ResourceModel\Tag\Collection $col */
            $col = $om->create(\Magento\Tag\Model\ResourceModel\Tag\Collection::class);
            if (!empty($args['name_like'])) {
                $col->addFieldToFilter('main_table.name', ['like' => $args['name_like']]);
            }
            if (!empty($args['join_rel'])) {
                $col->joinRel();
            }
            if (isset($args['status'])) {
                $col->addStatusFilter($args['status']);
            }
            if (isset($args['product_id'])) {
                $col->addProductFilter($args['product_id']);
            }
            if (isset($args['customer_id'])) {
                $col->addCustomerFilter($args['customer_id']);
            }
            if (!empty($args['active'])) {
                $col->setActiveFilter();
            }
            if (isset($args['store_filter'])) {
                $col->addStoreFilter($args['store_filter']);
            }
            if (isset($args['summary_store'])) {
                $col->addSummary($args['summary_store']);
            }
            if (!empty($args['tag_group'])) {
                $col->addTagGroup();
            }
            if (!empty($args['stores_visibility'])) {
                $col->addStoresVisibility();
            }
            $col->setOrder('main_table.tag_id', 'ASC');
            $col->load();
            $fields = ['tag_id', 'name', 'status', 'popularity', 'customers', 'products', 'stores'];
            $result = ['count' => count($col), 'size' => (int)$col->getSize(), 'items' => collectionItems($col, $fields)];
            break;

        case 'popular_collection':
            /** @var \Magento\Tag\Model\ResourceModel\Popular\Collection $col */
            $col = $om->create(\Magento\Tag\Model\ResourceModel\Popular\Collection::class);
            $col->joinFields($args['store_id'] ?? 0);
            if (isset($args['limit'])) {
                $col->limit($args['limit']);
            }
            $col->load();
            $items = collectionItems($col, ['tag_id', 'name', 'status', 'popularity']);
            if (!empty($args['name_prefix'])) {
                $items = array_values(array_filter($items, fn($i) => str_starts_with((string)$i['name'], $args['name_prefix'])));
            }
            $result = ['items' => $items];
            break;

        case 'product_collection':
            /** @var \Magento\Tag\Model\ResourceModel\Product\Collection $col */
            $col = $om->create(\Magento\Tag\Model\ResourceModel\Product\Collection::class);
            if (isset($args['tag_id'])) {
                $col->addTagFilter($args['tag_id']);
            }
            if (isset($args['customer_id'])) {
                $col->addCustomerFilter($args['customer_id']);
            }
            if (isset($args['store'])) {
                $col->addStoreFilter($args['store']);
            }
            if (isset($args['status'])) {
                $col->addStatusFilter($args['status']);
            }
            if (!empty($args['active'])) {
                $col->setActiveFilter();
            }
            if (isset($args['popularity_tag'])) {
                $col->addPopularity($args['popularity_tag'], $args['popularity_store'] ?? null);
            }
            $col->load();
            $items = collectionItems($col, ['entity_id', 'sku', 'name', 'tag_id', 'tag_name', 'tag_status', 'item_store_id', 'store_id', 'popularity']);
            usort($items, fn($a, $b) => (int)$a['entity_id'] <=> (int)$b['entity_id']);
            $result = ['count' => count($col), 'size' => (int)$col->getSize(), 'items' => $items];
            break;

        case 'customer_collection':
            /** @var \Magento\Tag\Model\ResourceModel\Customer\Collection $col */
            $col = $om->create(\Magento\Tag\Model\ResourceModel\Customer\Collection::class);
            if (isset($args['tag_id'])) {
                $col->addTagFilter($args['tag_id']);
            }
            if (isset($args['product_id'])) {
                $col->addProductFilter($args['product_id']);
            }
            if (isset($args['store'])) {
                $col->addStoreFilter($args['store']);
            }
            if (isset($args['status'])) {
                $col->addStatusFilter($args['status']);
            }
            if (isset($args['customer_id'])) {
                $col->addCustomerFilter($args['customer_id']);
            }
            if (isset($args['name'])) {
                $col->addFieldToFilter('name', $args['name']);
            }
            if (!empty($args['group_by_customer'])) {
                $col->addGroupByCustomer();
            }
            $col->load();
            if (!empty($args['product_name'])) {
                $col->addProductName();
            }
            $items = collectionItems($col, ['entity_id', 'email', 'firstname', 'tag_relation_id', 'tag_id', 'name', 'product_id', 'active', 'added_in', 'product', 'product_sku']);
            usort($items, fn($a, $b) => (int)$a['tag_relation_id'] <=> (int)$b['tag_relation_id']);
            $result = ['count' => count($col), 'items' => $items];
            break;

        // ---------------------------------------------------------------- helper
        case 'helper_extract':
            $h = $om->get(\Magento\Tag\Helper\Data::class);
            $raw = $h->extractTags($args['input']);
            $result = ['raw' => $raw, 'clean' => array_values($h->cleanTags($raw)), 'clean_keyed' => $h->cleanTags($raw)];
            break;

        case 'helper_clean':
            $h = $om->get(\Magento\Tag\Helper\Data::class);
            $result = ['clean' => $h->cleanTags($args['tags'])];
            break;

        case 'helper_statuses':
            $h = $om->get(\Magento\Tag\Helper\Data::class);
            $arr = [];
            foreach ($h->getStatusesArray() as $k => $v) {
                $arr[(string)$k] = (string)$v;
            }
            $opts = [];
            foreach ($h->getStatusesOptionsArray() as $o) {
                $opts[] = ['label' => (string)$o['label'], 'value' => $o['value']];
            }
            $result = ['statuses' => $arr, 'options' => $opts];
            break;

        case 'module_status':
            $ml = $om->get(\Magento\Framework\Module\ModuleListInterface::class);
            $result = [
                'enabled' => $ml->has('Magento_Tag'),
                'module' => $ml->getOne('Magento_Tag'),
                'constants' => [
                    'STATUS_DISABLED' => \Magento\Tag\Model\Tag::STATUS_DISABLED,
                    'STATUS_PENDING' => \Magento\Tag\Model\Tag::STATUS_PENDING,
                    'STATUS_APPROVED' => \Magento\Tag\Model\Tag::STATUS_APPROVED,
                    'ADD_STATUS_SUCCESS' => \Magento\Tag\Model\Tag::ADD_STATUS_SUCCESS,
                    'ADD_STATUS_NEW' => \Magento\Tag\Model\Tag::ADD_STATUS_NEW,
                    'ADD_STATUS_EXIST' => \Magento\Tag\Model\Tag::ADD_STATUS_EXIST,
                    'ADD_STATUS_REJECTED' => \Magento\Tag\Model\Tag::ADD_STATUS_REJECTED,
                ],
            ];
            break;

        default:
            out(['error' => 'unknown operation ' . $op, 'exception_class' => 'harness'], 3);
    }
    $result['operation_id'] = $operationId;
    out($result);
} catch (\Throwable $e) {
    out([
        'error' => $e->getMessage(),
        'exception_class' => get_class($e),
        'previous' => $e->getPrevious() ? $e->getPrevious()->getMessage() : null,
        'operation_id' => $operationId,
    ], 2);
}
