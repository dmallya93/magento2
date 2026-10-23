<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;

/**
 * Port of M1 Mage::app()->getStore()->isAdmin() for the Tag module
 *
 * Fails closed: only areas that map to the M1 admin store are treated as admin.
 * - adminhtml, crontab, global and "area not set" (CLI / setup scripts): admin, as M1 admin store scripts
 * - webapi_rest / webapi_soap: admin only for admin or integration tokens (the M1 API ran in the admin
 *   store); customer and guest tokens are storefront callers. Intentional deviation: M1 had no
 *   customer-facing web API.
 * - frontend, graphql and any other area: not admin
 */
class AdminContext
{
    /**
     * @var State
     */
    private $appState;

    /**
     * @var UserContextInterface
     */
    private $userContext;

    /**
     * @param State $appState
     * @param UserContextInterface $userContext
     */
    public function __construct(State $appState, UserContextInterface $userContext)
    {
        $this->appState = $appState;
        $this->userContext = $userContext;
    }

    /**
     * Whether the current request runs in an M1-equivalent admin context
     *
     * @return bool
     */
    public function isAdminContext(): bool
    {
        try {
            $areaCode = $this->appState->getAreaCode();
        } catch (LocalizedException $e) {
            // Area not set (CLI / setup scripts): treated like M1 admin store scripts.
            return true;
        }

        switch ($areaCode) {
            case Area::AREA_ADMINHTML:
            case Area::AREA_CRONTAB:
            case Area::AREA_GLOBAL:
                return true;
            case Area::AREA_WEBAPI_REST:
            case Area::AREA_WEBAPI_SOAP:
                return in_array(
                    (int)$this->userContext->getUserType(),
                    [UserContextInterface::USER_TYPE_ADMIN, UserContextInterface::USER_TYPE_INTEGRATION],
                    true
                );
            default:
                return false;
        }
    }
}
