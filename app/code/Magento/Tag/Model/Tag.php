<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tag\Model\Logging\Context as LogContext;
use Magento\Tag\Model\Logging\ErrorCodeResolver;
use Magento\Tag\Model\ResourceModel\Customer\Collection as CustomerCollection;
use Magento\Tag\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Tag\Model\ResourceModel\Popular\Collection as PopularCollection;
use Magento\Tag\Model\ResourceModel\Popular\CollectionFactory as PopularCollectionFactory;
use Magento\Tag\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Tag\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Tag\Model\ResourceModel\Tag\Collection as TagCollection;
use Magento\Tag\Model\ResourceModel\Tag\CollectionFactory as TagCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Tag entity model (port of Mage_Tag_Model_Tag)
 *
 * @method int getBasePopularity()
 * @method int getFirstCustomerId()
 * @method int getFirstStoreId()
 * @method int getStatus()
 * @method array|int|string|null getStatusFilter()
 * @method int getStore()
 * @method int getStoreId()
 * @method array getVisibleInStoreIds()
 * @method bool hasBasePopularity()
 * @method bool hasStoreId()
 * @method $this setBasePopularity(int $value)
 * @method $this setFirstCustomerId(int $value)
 * @method $this setFirstStoreId(int $value)
 * @method $this setName(string $value)
 * @method $this setStatus(int $value)
 * @method $this setStore(int $value)
 * @method $this setStoreId(int $value)
 * @method $this setStatusFilter($value)
 * @method $this setVisibleInStoreIds(array $value)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 */
class Tag extends AbstractModel implements IdentityInterface
{
    public const STATUS_DISABLED = -1;
    public const STATUS_PENDING = 0;
    public const STATUS_APPROVED = 1;

    /**
     * Statuses for tag relation add
     */
    public const ADD_STATUS_SUCCESS = 'success';
    public const ADD_STATUS_NEW = 'new';
    public const ADD_STATUS_EXIST = 'exist';
    public const ADD_STATUS_REJECTED = 'rejected';

    /**
     * Entity code. Can be used as part of method name for entity processing
     */
    public const ENTITY = 'tag';

    /**
     * Cache tag (IdentityInterface)
     */
    public const CACHE_TAG = 'tag';

    /**
     * Decision path slugs of saveRelation() (Project Spec DD10 / DD14)
     */
    public const DECISION_APPROVED_PRODUCT_UNTAGGED = 'approved.product_untagged';
    public const DECISION_APPROVED_CUSTOMER_UNLINKED = 'approved.customer_unlinked';
    public const DECISION_APPROVED_CUSTOMER_LINK_INACTIVE = 'approved.customer_link_inactive';
    public const DECISION_APPROVED_CUSTOMER_LINK_ACTIVE = 'approved.customer_link_active';
    public const DECISION_PENDING_CUSTOMER_UNLINKED = 'pending.customer_unlinked';
    public const DECISION_PENDING_CUSTOMER_LINK_INACTIVE = 'pending.customer_link_inactive';
    public const DECISION_PENDING_CUSTOMER_LINK_ACTIVE = 'pending.customer_link_active';
    public const DECISION_DISABLED_CUSTOMER_ALREADY_LINKED = 'disabled.customer_already_linked';
    public const DECISION_DISABLED_REOPEN_TO_PENDING = 'disabled.reopen_to_pending';

    /**
     * Persisted change slugs of saveRelation()
     */
    public const CHANGE_RELATION_CREATED = 'relation_created';
    public const CHANGE_RELATION_REACTIVATED = 'relation_reactivated';
    public const CHANGE_TAG_REOPENED_AND_RELATION_CREATED = 'tag_reopened_and_relation_created';
    public const CHANGE_NONE = 'none';

    /**
     * Event prefix for observer
     *
     * @var string
     */
    protected $_eventPrefix = 'tag';

    /**
     * This flag means should we or not add base popularity on tag load
     *
     * @var bool
     */
    protected $_addBasePopularity = false;

    /**
     * @var TagRelationFactory
     */
    private $tagRelationFactory;

    /**
     * @var TagCollectionFactory
     */
    private $tagCollectionFactory;

    /**
     * @var ProductCollectionFactory
     */
    private $productCollectionFactory;

    /**
     * @var CustomerCollectionFactory
     */
    private $customerCollectionFactory;

    /**
     * @var PopularCollectionFactory
     */
    private $popularCollectionFactory;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var OperationIdGenerator
     */
    private $operationIdGenerator;

    /**
     * @var AdminContext
     */
    private $adminContext;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param TagRelationFactory $tagRelationFactory
     * @param TagCollectionFactory $tagCollectionFactory
     * @param ProductCollectionFactory $productCollectionFactory
     * @param CustomerCollectionFactory $customerCollectionFactory
     * @param PopularCollectionFactory $popularCollectionFactory
     * @param StoreManagerInterface $storeManager
     * @param UrlInterface $urlBuilder
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     * @param OperationIdGenerator $operationIdGenerator
     * @param AdminContext $adminContext
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        TagRelationFactory $tagRelationFactory,
        TagCollectionFactory $tagCollectionFactory,
        ProductCollectionFactory $productCollectionFactory,
        CustomerCollectionFactory $customerCollectionFactory,
        PopularCollectionFactory $popularCollectionFactory,
        StoreManagerInterface $storeManager,
        UrlInterface $urlBuilder,
        DateTime $dateTime,
        LoggerInterface $logger,
        OperationIdGenerator $operationIdGenerator,
        AdminContext $adminContext,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->tagRelationFactory = $tagRelationFactory;
        $this->tagCollectionFactory = $tagCollectionFactory;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->customerCollectionFactory = $customerCollectionFactory;
        $this->popularCollectionFactory = $popularCollectionFactory;
        $this->storeManager = $storeManager;
        $this->urlBuilder = $urlBuilder;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
        $this->operationIdGenerator = $operationIdGenerator;
        $this->adminContext = $adminContext;
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(ResourceModel\Tag::class);
    }

    /**
     * Return cache identities of the tag
     *
     * @return string[]
     */
    public function getIdentities()
    {
        $identities = [self::CACHE_TAG . '_' . $this->getId()];
        $assignedProducts = $this->getData('tag_assigned_products');
        if (is_array($assignedProducts)) {
            foreach ($assignedProducts as $productId) {
                $identities[] = Product::CACHE_TAG . '_' . $productId;
            }
        }
        return array_values(array_unique($identities));
    }

    /**
     * Tag collection is created through the injected factory instead of the ObjectManager
     *
     * @return TagCollection|AbstractDb
     */
    public function getResourceCollection()
    {
        if ($this->_resourceCollection) {
            return parent::getResourceCollection();
        }
        return $this->tagCollectionFactory->create();
    }

    /**
     * Setter for addBasePopularity flag
     *
     * @param bool $flag
     * @return $this
     */
    public function setAddBasePopularity($flag = true)
    {
        $this->_addBasePopularity = (bool)$flag;
        return $this;
    }

    /**
     * Getter for addBasePopularity flag
     *
     * @return bool
     */
    public function getAddBasePopularity()
    {
        return $this->_addBasePopularity;
    }

    /**
     * Product event tags collection getter
     *
     * @param Observer $observer
     * @return TagCollection
     */
    protected function _getProductEventTagsCollection(Observer $observer)
    {
        return $this->getResourceCollection()
            ->joinRel()
            ->addProductFilter($observer->getEvent()->getProduct()->getId())
            ->addTagGroup()
            ->load();
    }

    /**
     * Popularity getter
     *
     * @return int|string|null
     */
    public function getPopularity()
    {
        return $this->_getData('popularity');
    }

    /**
     * Name getter
     *
     * @return string|null
     */
    public function getName()
    {
        return $this->_getData('name');
    }

    /**
     * Tag id getter
     *
     * @return int|string|null
     */
    public function getTagId()
    {
        return $this->_getData('tag_id');
    }

    /**
     * Ratio getter
     *
     * @return int|float|null
     */
    public function getRatio()
    {
        return $this->_getData('ratio');
    }

    /**
     * Ratio setter
     *
     * @param int|float $ratio
     * @return $this
     */
    public function setRatio($ratio)
    {
        $this->setData('ratio', $ratio);
        return $this;
    }

    /**
     * Load tag by name (name is truncated to 255 multibyte chars by the resource model)
     *
     * @param string $name
     * @return $this
     */
    public function loadByName($name)
    {
        $this->_getResource()->loadByName($this, $name);
        return $this;
    }

    /**
     * Aggregate tag summary data
     *
     * @return $this
     */
    public function aggregate()
    {
        $this->_getResource()->aggregate($this);
        return $this;
    }

    /**
     * Aggregate all tags of the product passed in the event
     *
     * @param Observer $observer
     * @return $this
     */
    public function productEventAggregate($observer)
    {
        $this->_getProductEventTagsCollection($observer)->walk('aggregate');
        return $this;
    }

    /**
     * Product delete event action
     *
     * @param Observer $observer
     * @return $this
     */
    public function productDeleteEventAction($observer)
    {
        $this->_getResource()->decrementProducts($this->_getProductEventTagsCollection($observer)->getAllIds());
        return $this;
    }

    /**
     * Add summary data to current object
     *
     * @param int $storeId
     * @return $this
     * @deprecated Kept for parity with Mage_Tag_Model_Tag::addSummary() (deprecated since 1.4.0.0)
     * @see \Magento\Tag\Model\ResourceModel\Tag\Collection::addSummary()
     */
    public function addSummary($storeId)
    {
        $this->setStoreId($storeId);
        $this->_getResource()->addSummary($this);
        return $this;
    }

    /**
     * Getter for self::STATUS_APPROVED
     *
     * @return int
     */
    public function getApprovedStatus()
    {
        return self::STATUS_APPROVED;
    }

    /**
     * Getter for self::STATUS_PENDING
     *
     * @return int
     */
    public function getPendingStatus()
    {
        return self::STATUS_PENDING;
    }

    /**
     * Getter for self::STATUS_DISABLED
     *
     * @return int
     */
    public function getDisabledStatus()
    {
        return self::STATUS_DISABLED;
    }

    /**
     * Products-by-tag collection
     *
     * @return ProductCollection
     */
    public function getEntityCollection()
    {
        return $this->productCollectionFactory->create();
    }

    /**
     * Customers-by-tag collection
     *
     * @return CustomerCollection
     */
    public function getCustomerCollection()
    {
        return $this->customerCollectionFactory->create();
    }

    /**
     * URL of products tagged by this tag
     *
     * @return string
     */
    public function getTaggedProductsUrl()
    {
        return $this->urlBuilder->getUrl('tag/product/list', ['tagId' => $this->getTagId()]);
    }

    /**
     * URL of the customer tag view page
     *
     * @return string
     */
    public function getViewTagUrl()
    {
        return $this->urlBuilder->getUrl('tag/customer/view', ['tagId' => $this->getTagId()]);
    }

    /**
     * URL of the customer tag edit page
     *
     * @return string
     */
    public function getEditTagUrl()
    {
        return $this->urlBuilder->getUrl('tag/customer/edit', ['tagId' => $this->getTagId()]);
    }

    /**
     * URL of the customer tag remove action
     *
     * @return string
     */
    public function getRemoveTagUrl()
    {
        return $this->urlBuilder->getUrl('tag/customer/remove', ['tagId' => $this->getTagId()]);
    }

    /**
     * Popular tags collection
     *
     * @return PopularCollection
     */
    public function getPopularCollection()
    {
        return $this->popularCollectionFactory->create();
    }

    /**
     * Retrieves array of related product IDs
     *
     * @return array
     */
    public function getRelatedProductIds()
    {
        return $this->tagRelationFactory->create()
            ->setTagId($this->getTagId())
            ->setStoreId($this->getStoreId())
            ->setStatusFilter($this->getStatusFilter())
            ->setCustomerId(null)
            ->getProductIds();
    }

    /**
     * Checks is available current tag in specified store
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isAvailableInStore($storeId = null)
    {
        $storeId ??= $this->storeManager->getStore()->getId();
        // phpcs:ignore Magento2.PHP.ReturnValueCheck -- loose comparison kept for M1 parity (ids are DB strings)
        return in_array($storeId, (array)$this->getVisibleInStoreIds());
    }

    /**
     * Protect tag deletion from the non-admin (storefront) area (port of M1 _beforeDelete/_protectFromNonAdmin)
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeDelete()
    {
        $this->_protectFromNonAdmin();
        return parent::beforeDelete();
    }

    /**
     * Port of Mage_Core_Model_Abstract::_protectFromNonAdmin()
     *
     * M1 allowed the operation in the secure area or in the admin store (admin panel, API, scripts);
     * the admin-store mapping lives in AdminContext and fails closed for storefront areas.
     *
     * @return void
     * @throws LocalizedException
     */
    protected function _protectFromNonAdmin()
    {
        if ($this->_registry->registry('isSecureArea')) {
            return;
        }
        if (!$this->adminContext->isAdminContext()) {
            throw new LocalizedException(__('Cannot complete this operation from non-admin area.'));
        }
    }

    /**
     * Save tag relation with product, customer and store
     *
     * Implements the 9-path / 4-result decision table of Project Spec Design Decision 10 exactly as
     * Mage_Tag_Model_Tag::saveRelation() does.
     *
     * @param int|string $productId
     * @param int|string|null $customerId
     * @param int|string|null $storeId
     * @return string relation add status
     * @throws \Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function saveRelation($productId, $customerId, $storeId)
    {
        $startTime = microtime(true);
        $statusBefore = $this->getStatus();
        $context = [
            'module' => LogContext::MODULE,
            'operation_id' => $this->operationIdGenerator->getOperationId(),
            'tag_id' => $this->getId(),
            'customer_id' => $customerId,
            'product_id' => $productId,
            'store_id' => $storeId,
        ];
        $this->logger->info(
            'Tag relation save started',
            LogContext::merge($context, ['operation' => 'relation.save', 'status' => $statusBefore])
        );

        $decisionPath = null;
        $persistedChange = self::CHANGE_NONE;
        $inactiveLinksCounted = false;
        $result = '';

        try {
            /** @var TagRelation $relationModel */
            $relationModel = $this->tagRelationFactory->create();
            $relationModel->setTagId($this->getId())
                ->setStoreId($storeId)
                ->setProductId($productId)
                ->setCustomerId($customerId)
                ->setActive(TagRelation::STATUS_ACTIVE)
                ->setCreatedAt($this->dateTime->gmtDate());

            $relationModelSaveNeed = false;
            $reopenTag = false;

            // switch performs loose comparison, exactly like M1
            switch ($this->getStatus()) {
                case $this->getApprovedStatus():
                    if ($this->_checkLinkBetweenTagProduct($relationModel)) {
                        $relation = $this->_getLinkBetweenTagCustomerProduct($relationModel);
                        if ($relation->getId()) {
                            if (!$relation->getActive()) {
                                // activate relation if it was inactive
                                $relationModel->setId($relation->getId());
                                $relationModelSaveNeed = true;
                                $decisionPath = self::DECISION_APPROVED_CUSTOMER_LINK_INACTIVE;
                                $persistedChange = self::CHANGE_RELATION_REACTIVATED;
                            } else {
                                $decisionPath = self::DECISION_APPROVED_CUSTOMER_LINK_ACTIVE;
                            }
                        } else {
                            $relationModelSaveNeed = true;
                            $decisionPath = self::DECISION_APPROVED_CUSTOMER_UNLINKED;
                            $persistedChange = self::CHANGE_RELATION_CREATED;
                        }
                        $inactiveLinksCounted = $this->isProductTaggedOnlyByInactiveLinks($relationModel);
                        $result = self::ADD_STATUS_EXIST;
                    } else {
                        $relationModelSaveNeed = true;
                        $decisionPath = self::DECISION_APPROVED_PRODUCT_UNTAGGED;
                        $persistedChange = self::CHANGE_RELATION_CREATED;
                        $result = self::ADD_STATUS_SUCCESS;
                    }
                    break;
                case $this->getPendingStatus():
                    $relation = $this->_getLinkBetweenTagCustomerProduct($relationModel);
                    if ($relation->getId()) {
                        if (!$relation->getActive()) {
                            $relationModel->setId($relation->getId());
                            $relationModelSaveNeed = true;
                            $decisionPath = self::DECISION_PENDING_CUSTOMER_LINK_INACTIVE;
                            $persistedChange = self::CHANGE_RELATION_REACTIVATED;
                        } else {
                            $decisionPath = self::DECISION_PENDING_CUSTOMER_LINK_ACTIVE;
                        }
                    } else {
                        $relationModelSaveNeed = true;
                        $decisionPath = self::DECISION_PENDING_CUSTOMER_UNLINKED;
                        $persistedChange = self::CHANGE_RELATION_CREATED;
                    }
                    $result = self::ADD_STATUS_NEW;
                    break;
                case $this->getDisabledStatus():
                    if ($this->_checkLinkBetweenTagCustomerProduct($relationModel)) {
                        $decisionPath = self::DECISION_DISABLED_CUSTOMER_ALREADY_LINKED;
                        $result = self::ADD_STATUS_REJECTED;
                    } else {
                        $reopenTag = true;
                        $relationModelSaveNeed = true;
                        $decisionPath = self::DECISION_DISABLED_REOPEN_TO_PENDING;
                        $persistedChange = self::CHANGE_TAG_REOPENED_AND_RELATION_CREATED;
                        $result = self::ADD_STATUS_NEW;
                    }
                    break;
            }

            if ($decisionPath !== null) {
                $this->logger->info(
                    'Tag relation decision made',
                    LogContext::merge($context, [
                        'operation' => 'relation.decide',
                        'decision_path' => $decisionPath,
                        'result_code' => $result,
                        'persisted_change' => $persistedChange,
                        'tag_status_before' => $statusBefore,
                        'inactive_links_counted' => $inactiveLinksCounted,
                    ])
                );
            } else {
                // M1 falls through the switch for an unknown status: nothing is saved and '' is returned.
                $this->logger->warning(
                    'Tag relation decision skipped: unknown tag status',
                    LogContext::merge($context, [
                        'operation' => 'relation.decide_skipped',
                        'error_code' => 'relation.decide.unknown_status',
                        'tag_status_before' => $statusBefore,
                    ])
                );
            }

            if ($reopenTag) {
                // The status lives on the tag row, so the flip back to PENDING applies in every store.
                $this->setStatus($this->getPendingStatus())->save();
                $this->logger->warning(
                    'Disabled tag automatically reopened to pending by customer submission',
                    LogContext::merge($context, [
                        'operation' => 'tag.status.auto_reopened',
                        'error_code' => 'tag.status.auto_reopened',
                        'previous_status' => self::STATUS_DISABLED,
                        'new_status' => self::STATUS_PENDING,
                        'scope' => 'global',
                    ])
                );
            }

            if ($relationModelSaveNeed) {
                $relationModel->save();
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Tag relation save failed',
                LogContext::merge($context, [
                    'operation' => 'relation.save',
                    'outcome' => 'failure',
                    'error_code' => ErrorCodeResolver::resolve($e, 'relation.save'),
                    'decision_path' => $decisionPath,
                    'exception' => $e->getMessage(),
                    'duration_ms' => LogContext::durationMs($startTime),
                ])
            );
            throw $e;
        }

        $this->logger->info(
            'Tag relation save completed',
            LogContext::merge($context, [
                'operation' => 'relation.save',
                'outcome' => $decisionPath === null ? 'skipped' : 'success',
                'result_code' => $result,
                'decision_path' => $decisionPath,
                'persisted_change' => $persistedChange,
                'status' => $this->getStatus(),
                'duration_ms' => LogContext::durationMs($startTime),
            ])
        );

        return $result;
    }

    /**
     * Check whether product is already marked in store with tag (relation `active` flag is ignored, as in M1)
     *
     * @param TagRelation $relationModel
     * @return bool
     */
    protected function _checkLinkBetweenTagProduct($relationModel)
    {
        $customerId = $relationModel->getCustomerId();
        $relationModel->setCustomerId(null);
        // phpcs:ignore Magento2.PHP.ReturnValueCheck -- loose comparison kept for M1 parity
        $result = in_array($relationModel->getProductId(), (array)$relationModel->getProductIds());
        $relationModel->setCustomerId($customerId);
        return $result;
    }

    /**
     * Check whether product is already marked in store with tag by customer
     *
     * @param TagRelation $relationModel
     * @return bool
     */
    protected function _checkLinkBetweenTagCustomerProduct($relationModel)
    {
        return count((array)$this->_getLinkBetweenTagCustomerProduct($relationModel)->getProductIds()) > 0;
    }

    /**
     * Get relation model for product marked in store with tag by customer
     *
     * @param TagRelation $relationModel
     * @return TagRelation
     */
    protected function _getLinkBetweenTagCustomerProduct($relationModel)
    {
        return $this->tagRelationFactory->create()->loadByTagCustomer(
            $relationModel->getProductId(),
            $this->getId(),
            $relationModel->getCustomerId(),
            $relationModel->getStoreId()
        );
    }

    /**
     * Read-only diagnostic: product counted as "already tagged" only because of inactive relations
     *
     * Used solely for the `inactive_links_counted` log field; it never changes the decision.
     *
     * @param TagRelation $relationModel
     * @return bool
     */
    private function isProductTaggedOnlyByInactiveLinks(TagRelation $relationModel): bool
    {
        try {
            return !$relationModel->hasActiveProductRelation();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Processing object after save data
     *
     * @return $this
     */
    public function afterSave()
    {
        if ($this->hasData('tag_assigned_products')) {
            $tagRelationModel = $this->tagRelationFactory->create();
            $tagRelationModel->addRelations($this, $this->getData('tag_assigned_products'));
        }

        return parent::afterSave();
    }

}
