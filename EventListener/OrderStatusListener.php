<?php

namespace ShoppingFeed\EventListener;

use ShoppingFeed\Exception\ShoppingfeedException;
use ShoppingFeed\Model\ShoppingfeedFeedQuery;
use ShoppingFeed\Model\ShoppingfeedMappingDeliveryQuery;
use ShoppingFeed\Model\ShoppingfeedOrderDataQuery;
use ShoppingFeed\Sdk\Api\Order\OrderOperation;
use ShoppingFeed\Service\ApiService;
use ShoppingFeed\Service\LogService;
use ShoppingFeed\ShoppingFeed;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Translation\Translator;
use Thelia\Model\OrderStatus;

class OrderStatusListener implements EventSubscriberInterface
{
    /** @var ApiService  */
    protected $apiService;

    /** @var LogService  */
    protected $logger;

    public function __construct(ApiService $apiService, LogService $logService)
    {
        $this->apiService = $apiService;
        $this->logger = $logService;
    }

    public function onOrderUpdateStatus(OrderEvent $event)
    {
        $order = $event->getOrder();
        $orderData = ShoppingfeedOrderDataQuery::create()->filterById($order->getId())->findOne();
        $feed = ShoppingfeedFeedQuery::create()->filterById($orderData->getFeedId())->findOne();

        if ($orderData) {
            try {
                $orderOperation = new OrderOperation();
                $orderStatusCode = $order->getOrderStatus()->getCode();
                if ($orderStatusCode === OrderStatus::CODE_CANCELED) {
                    $orderOperation->cancel($orderData->getExternalReference(), $orderData->getChannel());
                }
                if ($orderStatusCode === OrderStatus::CODE_SENT) {
                    $mapping = ShoppingfeedMappingDeliveryQuery::create()->filterByModuleId($order->getDeliveryModuleId())->findOne();
                    if (!$mapping) {
                        throw new ShoppingfeedException(
                            $feed,
                            Translator::getInstance()->trans(
                                "This delivery module is not mapped.",
                                [],
                                ShoppingFeed::DOMAIN_NAME
                            ),
                            Translator::getInstance()->trans(
                                "To create this mapping, go to Mapping Delivery tab (see link in extra)",
                                [],
                                ShoppingFeed::DOMAIN_NAME
                            ),
                            LogService::LEVEL_ERROR,
                            null,
                            'Mapping',
                            null
                        );
                    }
                    $orderOperation->ship($orderData->getExternalReference(), $orderData->getChannel(), $mapping->getCode(), $order->getDeliveryRef());
                }
                $orderApi = $this->apiService->getFeedStore($feed)->getOrderApi();
                $orderApi->execute($orderOperation);
            } catch (ShoppingfeedException $shoppingfeedException) {
                $this->logger->logShoppingfeedException($shoppingfeedException);
            } catch (\Exception $exception) {
                $this->logger->log(
                    $feed,
                    $exception->getMessage(),
                    LogService::LEVEL_ERROR,
                    $order->getId(),
                    'Order'
                );
            }
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            TheliaEvents::ORDER_UPDATE_STATUS => ['onOrderUpdateStatus', 64]
        ];
    }
}