<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Tag\Model\AdminContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the M1 isAdmin() port (fail-closed admin-area allowlist)
 */
#[CoversClass(AdminContext::class)]
#[AllowMockObjectsWithoutExpectations]
class AdminContextTest extends TestCase
{
    /**
     * Each area / user type maps to the expected admin decision
     *
     * @param string $areaCode
     * @param int|null $userType
     * @param bool $expected
     * @return void
     */
    #[DataProvider('areaDataProvider')]
    public function testIsAdminContext(string $areaCode, ?int $userType, bool $expected): void
    {
        $appState = $this->createMock(State::class);
        $appState->method('getAreaCode')->willReturn($areaCode);
        $userContext = $this->createMock(UserContextInterface::class);
        $userContext->method('getUserType')->willReturn($userType);

        $this->assertSame($expected, (new AdminContext($appState, $userContext))->isAdminContext());
    }

    /**
     * @return array
     */
    public static function areaDataProvider(): array
    {
        return [
            'adminhtml' => [Area::AREA_ADMINHTML, null, true],
            'crontab' => [Area::AREA_CRONTAB, null, true],
            'global' => [Area::AREA_GLOBAL, null, true],
            'frontend' => [Area::AREA_FRONTEND, null, false],
            'graphql customer' => [Area::AREA_GRAPHQL, UserContextInterface::USER_TYPE_CUSTOMER, false],
            'graphql admin token' => [Area::AREA_GRAPHQL, UserContextInterface::USER_TYPE_ADMIN, false],
            'rest admin' => [Area::AREA_WEBAPI_REST, UserContextInterface::USER_TYPE_ADMIN, true],
            'rest integration' => [Area::AREA_WEBAPI_REST, UserContextInterface::USER_TYPE_INTEGRATION, true],
            'rest customer' => [Area::AREA_WEBAPI_REST, UserContextInterface::USER_TYPE_CUSTOMER, false],
            'rest guest' => [Area::AREA_WEBAPI_REST, UserContextInterface::USER_TYPE_GUEST, false],
            'soap admin' => [Area::AREA_WEBAPI_SOAP, UserContextInterface::USER_TYPE_ADMIN, true],
            'soap guest' => [Area::AREA_WEBAPI_SOAP, UserContextInterface::USER_TYPE_GUEST, false],
            'unknown area' => ['doc', null, false],
        ];
    }

    /**
     * Area not set (CLI / setup scripts) counts as admin, like M1 admin store scripts
     *
     * @return void
     */
    public function testAreaNotSetIsAdmin(): void
    {
        $appState = $this->createMock(State::class);
        $appState->method('getAreaCode')->willThrowException(new LocalizedException(__('Area code is not set')));

        $this->assertTrue(
            (new AdminContext($appState, $this->createMock(UserContextInterface::class)))->isAdminContext()
        );
    }
}
