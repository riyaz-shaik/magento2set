<?php

/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Quote\Test\Unit\Model\Quote\Address\Total;

use Magento\Catalog\Api\Data\ProductExtensionInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\Price;
use Magento\CatalogInventory\Model\Stock\Item;
use Magento\CatalogInventory\Model\StockRegistry;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Api\Data\ShippingInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\Subtotal;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * TODO refactor me
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SubtotalTest extends TestCase
{
    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var Subtotal
     */
    protected $subtotalModel;

    /** @var MockObject */
    protected $stockItemMock;

    /**
     * @var MockObject
     */
    protected $stockRegistry;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->subtotalModel = $this->objectManager->getObject(Subtotal::class);

        $this->stockRegistry = $this->createPartialMock(StockRegistry::class, ['getStockItem']);
        $this->stockItemMock = $this->createPartialMock(Item::class, ['getIsInStock']);
    }

    /**
     * @return array
     */
    public function collectDataProvider()
    {
        return [
            [12, 10, false, 12, 10],
            [12, 0, false, 12, 12],
            [0, 10, false, 0, 10],
            [12, 10, true, null, null],
            [12, 10, false, 12, 10]
        ];
    }

    /**
     * @dataProvider collectDataProvider
     *
     * @param int $price
     * @param int $originalPrice
     * @param bool $itemHasParent
     * @param int $expectedPrice
     * @param int $expectedOriginalPrice
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testCollect($price, $originalPrice, $itemHasParent, $expectedPrice, $expectedOriginalPrice)
    {
        $this->stockRegistry->expects($this->any())->method('getStockItem')->willReturn($this->stockItemMock);

        $priceCurrency = $this->getMockBuilder(PriceCurrencyInterface::class)
            ->getMock();
        $convertedPrice = 1231313;
        // @TODO this is a wrong test and it does not check methods. Any digital value will be correct
        $priceCurrency->expects($this->any())->method('convert')->willReturn(1231313);

        /** @var \Magento\Quote\Model\Quote\Item|MockObject $quoteItem */
        $quoteItem = $this->objectManager->getObject(
            \Magento\Quote\Model\Quote\Item::class,
            [
                'stockRegistry' => $this->stockRegistry,
                'priceCurrency' => $priceCurrency,
            ]
        );
        /** @var Address|MockObject $address */
        $address = $this->getMockBuilder(Address::class)
            ->addMethods(['setTotalQty', 'getTotalQty'])
            ->onlyMethods(['removeItem', 'getQuote'])
            ->disableOriginalConstructor()
            ->getMock();

        /** @var Product|MockObject $product */
        $product = $this->createMock(Product::class);
        $product->expects($this->any())->method('getPrice')->willReturn($originalPrice);

        /** @var Quote|MockObject $quote */
        $quote = $this->createMock(Quote::class);
        $store = $this->objectManager->getObject(Store::class);
        $store->setCurrentCurrency('');

        $store = $this->createPartialMock(Store::class, ['getWebsiteId']);
        $store->expects($this->any())->method('getWebsiteId')->willReturn(10);
        $product->expects($this->any())->method('getStore')->willReturn($store);
        $product->expects($this->any())->method('isVisibleInCatalog')->willReturn(true);
        $extensionAttribute = $this->getMockBuilder(ProductExtensionInterface::class)
            ->setMethods(['getStockItem'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $extensionAttribute->expects($this->atLeastOnce())
            ->method('getStockItem')
            ->willReturn($this->stockItemMock);
        $product->expects($this->atLeastOnce())->method('getExtensionAttributes')->willReturn($extensionAttribute);
        $quote->expects($this->any())->method('getStore')->willReturn($store);
        $quoteItem->setProduct($product)->setQuote($quote);

        $parentQuoteItem = false;
        if ($itemHasParent) {
            $parentQuoteItem = $this->createMock(\Magento\Quote\Model\Quote\Item::class);
            $parentQuoteItem->expects($this->any())->method('getProduct')->willReturn($product);
        }
        $quoteItem->setParentItem($parentQuoteItem);
        //This value will be overwritten
        $quoteItem->setConvertedPrice(10);

        $priceModel = $this->createMock(Price::class);
        $priceModel->expects($this->any())->method('getChildFinalPrice')->willReturn($price);
        $product->expects($this->any())->method('getPriceModel')->willReturn($priceModel);
        $product->expects($this->any())->method('getFinalPrice')->willReturn($price);

        $shipping = $this->getMockForAbstractClass(ShippingInterface::class);
        $shipping->expects($this->exactly(2))->method('getAddress')->willReturn($address);
        $address->expects($this->at(0))->method('setTotalQty')->with(0);
        $address->expects($this->any())->method('getTotalQty')->willReturn(0);
        $shippingAssignmentMock = $this->getMockForAbstractClass(ShippingAssignmentInterface::class);
        $shippingAssignmentMock->expects($this->exactly(2))->method('getShipping')->willReturn($shipping);
        $shippingAssignmentMock->expects($this->once())->method('getItems')->willReturn([$quoteItem]);

        $total = $this->getMockBuilder(Total::class)
            ->addMethods(['setBaseVirtualAmount', 'setVirtualAmount'])
            ->disableOriginalConstructor()
            ->getMock();
        $total->expects($this->once())->method('setBaseVirtualAmount')->willReturnSelf();
        $total->expects($this->once())->method('setVirtualAmount')->willReturnSelf();

        $this->subtotalModel->collect($quote, $shippingAssignmentMock, $total);

        $this->assertEquals($expectedPrice, $quoteItem->getPrice());
        $this->assertEquals($expectedOriginalPrice, $quoteItem->getBaseOriginalPrice());
        $this->assertEquals($convertedPrice, $quoteItem->getCalculationPrice());
        $this->assertEquals($convertedPrice, $quoteItem->getConvertedPrice());
    }

    public function testFetch()
    {
        $expectedResult = [
            'code' => null,
            'title' => __('Subtotal'),
            'value' => 100
        ];

        $quoteMock = $this->createMock(Quote::class);
        $totalMock = $this->getMockBuilder(Total::class)
            ->addMethods(['getSubtotal'])
            ->disableOriginalConstructor()
            ->getMock();
        $totalMock->expects($this->once())->method('getSubtotal')->willReturn(100);

        $this->assertEquals($expectedResult, $this->subtotalModel->fetch($quoteMock, $totalMock));
    }
}
