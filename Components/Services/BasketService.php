<?php

namespace MollieShopware\Components\Services;

use Enlight_Components_Db_Adapter_Pdo_Mysql;
use Exception;
use MollieShopware\Components\Config;
use MollieShopware\Components\Logger;
use Shopware\Components\Model\ModelManager;
use Shopware\Models\Order\Basket;
use Shopware\Models\Order\Detail;
use Shopware\Models\Order\Order;
use Shopware\Models\Order\Repository;
use Shopware\Models\Order\Status;
use Shopware\Models\Voucher\Voucher;
use Shopware_Components_Modules;
use Zend_Db_Adapter_Exception;

class BasketService
{
    /** @var Config $config */
    protected $config;

    /** @var ModelManager $modelManager */
    protected $modelManager;

    /** @var Shopware_Components_Modules $basketModule */
    protected $basketModule;

    /** @var OrderService $orderService */
    protected $orderService;

    /** @var Enlight_Components_Db_Adapter_Pdo_Mysql|null */
    protected $db;

    /**
     * Constructor
     *
     * @param ModelManager $modelManager
     */
    public function __construct(ModelManager $modelManager)
    {
        $this->config = Shopware()->Container()
            ->get('mollie_shopware.config');

        $this->modelManager = $modelManager;

        $this->basketModule = Shopware()->Modules()->Basket();

        $this->orderService = Shopware()->Container()
            ->get('mollie_shopware.order_service');

        $this->db = Shopware()->Container()->get('db');
    }

    /**
     * Restore Basket
     *
     * @param Order|int $orderId
     *
     * @throws Exception
     */
    public function restoreBasket($orderId)
    {
        // get the order model
        if ($orderId instanceof Order) {
            $order = $orderId;
        } else {
            $order = $this->orderService->getOrderById($orderId);
        }

        if (!empty($order)) {
            // get order details
            $orderDetails = $order->getDetails();

            if (!empty($orderDetails)) {
                // clear basket
                $this->basketModule->sDeleteBasket();

                // set comment
                $commentText = "The payment on this order failed, the customer is retrying. ";

                // iterate over products and add them to the basket
                foreach ($orderDetails as $orderDetail) {
                    $result = null;

                    if ($orderDetail->getMode() == 2) {
                        // get voucher from database
                        $voucher = $this->getVoucherById($orderDetail->getArticleId());

                        if (!empty($voucher)) {
                            // remove voucher from original order
                            $this->removeOrderDetail($orderDetail->getId());

                            // set comment
                            $commentText = $commentText . "Voucher code (" . $voucher->getVoucherCode() .
                                ") is removed van this order and reused in the newly created basket. ";

                            // add voucher to basket
                            $this->basketModule->sAddVoucher($voucher->getVoucherCode());

                            // restore order price
                            $order->setInvoiceAmount($order->getInvoiceAmount() - $orderDetail->getPrice());
                        }
                    } else {
                        // add product to basket
                        $id = $this->basketModule->sAddArticle(
                            $orderDetail->getArticleNumber(),
                            $orderDetail->getQuantity()
                        );

                        if (is_int($id)) {
                            // set attributes
                            $this->addAttributes($id, $orderDetail);
                        }
                    }

                    // reset ordered quantity
                    if ($this->config->autoResetStock()) {
                        $this->resetOrderDetailQuantity($orderDetail);
                    }
                }

                // append internal comment
                if (!strstr($order->getInternalComment(), $commentText))
                    $order = $this->appendInternalComment($order, $commentText);

                // recalculate order
                $order->calculateInvoiceAmount();

                /** @var Status $statusCanceled */
                $statusCanceled = Shopware()->Container()->get('models')->getRepository(
                    Status::class
                )->find(
                    Status::PAYMENT_STATE_THE_PROCESS_HAS_BEEN_CANCELLED
                );

                // set payment status
                if ($this->config->cancelFailedOrders()) {
                    /** @var OrderHistoryService $historyService */
                    $historyService = Shopware()->Container()->get('mollie_shopware.order_history_service');

                    // add item to the history
                    if ($historyService !== null) {
                        $historyService->addOrderHistory(
                            $order,
                            $order->getOrderStatus()->getId(),
                            $order->getOrderStatus()->getId(),
                            $statusCanceled->getId(),
                            $order->getPaymentStatus()->getId()
                        );
                    }

                    $order->setPaymentStatus($statusCanceled);
                }

                // save order
                $this->modelManager->persist($order);
                $this->modelManager->flush();
            }
        }

        // refresh the basket
        $this->basketModule->sRefreshBasket();
    }

    /**
     * Adds order details attributes to restored order basket attributes
     *
     * @param int $id
     * @param Detail $orderDetail
     * @throws Zend_Db_Adapter_Exception
     */
    function addAttributes($id, Detail $orderDetail)
    {
        // load all order basket attributes
        $orderBasketAttributes = $this->getOrderBasketAttributes($id);

        if ($orderBasketAttributes === null) {
            return;
        }

        // load all order details attributes
        $orderDetailAttributes = $this->getOrderDetailsAttributes($orderDetail->getId());

        if (!$orderDetailAttributes) {
            return;
        }

        // create update array
        $update = [];

        foreach ($orderBasketAttributes as $key => $attribute) {
            // remove id columns
            if (in_array($key, ['id', 'basketID', 'basket_item_id'])) {
                continue;
            }

            // check if attribute exists in order details
            if (!array_key_exists($key, $orderDetailAttributes)) {
                continue;
            }

            // add attribute
            $update[$key] = $orderDetailAttributes[$key];
        }

        // perform update
        $this->db->update('s_order_basket_attributes', $update, 'id = ' . $id);
    }

    /**
     * @param int $id
     * @return array|null
     */
    private function getOrderBasketAttributes($id)
    {
        $attributesResult = $this->db->fetchAll(
            'SELECT * FROM s_order_basket_attributes WHERE basketID = ?;',
            [$id]
        );

        if (!$attributesResult) {
            return null;
        }

        if (!array_key_exists(0, $attributesResult)) {
            return null;
        }

        return $attributesResult[0];
    }

    /**
     * @param int $id
     * @return array|null
     */
    private function getOrderDetailsAttributes($id)
    {
        $orderDetailAttributesResult = $this->db->fetchAll(
            'SELECT * FROM s_order_details_attributes WHERE id = ?;',
            [$id]
        );

        if (!$orderDetailAttributesResult) {
            return null;
        }

        if (!array_key_exists(0, $orderDetailAttributesResult)) {
            return null;
        }

        return $orderDetailAttributesResult[0];
    }

    /**
     * Get positions from basket
     *
     * @param array $userData
     * @return array
     * @throws Exception
     */
    function getBasketLines($userData = array())
    {
        $items = [];

        try {
            /** @var Repository $basketRepo */
            $basketRepo = Shopware()->Container()->get('models')->getRepository(
                Basket::class
            );

            /** @var Basket[] $basketItems */
            $basketItems = $basketRepo->findBy([
                'sessionId' => Shopware()->Session()->offsetGet('sessionId')
            ]);

            foreach ($basketItems as $basketItem) {
                $unitPrice = round($basketItem->getPrice(), 2);
                $netPrice = round($basketItem->getNetPrice(), 2);
                $totalAmount = $unitPrice * $basketItem->getQuantity();
                $vatAmount = 0;

                if (
                    isset($userData['additional']['charge_vat'], $userData['additional']['show_net'])
                    && $userData['additional']['charge_vat'] === true
                    && $userData['additional']['show_net'] === false
                ) {
                    $unitPrice = $unitPrice * ($basketItem->getTaxRate() + 100) / 100;
                    $unitPrice = round($unitPrice, 2);
                    $totalAmount = $unitPrice * $basketItem->getQuantity();
                }

                if (
                    isset($userData['additional']['charge_vat'])
                    && $userData['additional']['charge_vat'] === true
                ) {
                    $vatAmount = $totalAmount * ($basketItem->getTaxRate() / ($basketItem->getTaxRate() + 100));
                    $vatAmount = round($vatAmount, 2);
                }

                // build the order line array
                $orderLine = [
                    'basket_item_id' => $basketItem->getId(),
                    'article_id' => $basketItem->getArticleId(),
                    'name' => $basketItem->getArticleName(),
                    'type' => $this->getOrderType($basketItem, $unitPrice),
                    'quantity' => $basketItem->getQuantity(),
                    'unit_price' => $unitPrice,
                    'net_price' => $netPrice,
                    'total_amount' => $totalAmount,
                    'vat_rate' => $vatAmount == 0 ? 0 : $basketItem->getTaxRate(),
                    'vat_amount' => $vatAmount,
                ];

                if (
                    $basketItem !== null
                    && $basketItem->getAttribute() !== null
                    && method_exists($basketItem->getAttribute(), 'setBasketItemId')
                ) {
                    $basketItem->getAttribute()->setBasketItemId($basketItem->getId());

                    $this->modelManager->persist($basketItem);
                    $this->modelManager->flush($basketItem);
                }

                // add the order line to items
                $items[] = $orderLine;
            }
        } catch (Exception $ex) {
            Logger::log(
                'error',
                $ex->getMessage(),
                $ex
            );
        }

        return $items;
    }

    /**
     * @param $basketItem
     * @param float $unitPrice
     * @return string
     */
    private function getOrderType($basketItem, $unitPrice)
    {
        // set the order line type
        if (strpos($basketItem->getOrderNumber(), 'surcharge') !== false) {
            return 'surcharge';
        }

        if (strpos($basketItem->getOrderNumber(), 'discount') !== false) {
            return 'discount';
        }

        if ($basketItem->getEsdArticle() > 0) {
            return 'digital';
        }

        if ($basketItem->getMode() == 2) {
            return 'discount';
        }

        if ($unitPrice < 0) {
            return 'discount';
        }

        return 'physical';
    }

    /**
     * Get a voucher by it's id
     *
     * @param int $voucherId
     *
     * @return Voucher $voucher
     *
     * @throws Exception
     */
    public function getVoucherById($voucherId)
    {
        $voucher = null;

        try {
            /** @var \Shopware\Models\Voucher\Repository $voucherRepo */
            $voucherRepo = $this->modelManager->getRepository(
                Voucher::class
            );

            /** @var Voucher $voucher */
            $voucher = $voucherRepo->findOneBy([
                'id' => $voucherId
            ]);
        } catch (Exception $ex) {
            Logger::log(
                'error',
                $ex->getMessage(),
                $ex
            );
        }

        return $voucher;
    }

    /**
     * Remove detail from order
     *
     * @param int $orderDetailId
     *
     * @return int $result
     *
     * @throws Exception
     */
    public function removeOrderDetail($orderDetailId)
    {
        $result = null;

        try {
            // init db
            $db = Shopware()->Container()->get('db');

            // prepare database statement
            $q = $db->prepare('
                DELETE FROM 
                s_order_details 
                WHERE id=?
            ');

            // execute sql query
            $result = $q->execute([
                $orderDetailId,
            ]);
        } catch (Exception $ex) {
            Logger::log(
                'error',
                $ex->getMessage(),
                $ex
            );
        }

        return $result;
    }

    /**
     * Reset the order quantity for a canceled order
     *
     * @param Detail $orderDetail
     *
     * @return Detail $orderDetail
     *
     * @throws Exception
     */
    public function resetOrderDetailQuantity(Detail $orderDetail) {
        // reset quantity
        $orderDetail->setQuantity(0);

        try {
            $this->modelManager->persist($orderDetail);
            $this->modelManager->flush($orderDetail);
        } catch (Exception $e) {
            //
        }

        // build order detail repository
        $articleDetailRepository = Shopware()->Container()->get('models')->getRepository(
            \Shopware\Models\Article\Detail::class
        );

        try {
            /** @var \Shopware\Models\Article\Detail $article */
            $article = $articleDetailRepository->findOneBy([
                'number' => $orderDetail->getArticleNumber()
            ]);
        } catch (Exception $ex) {
            //
        }

        // restore stock
        if ($article !== null) {
            $article->setInStock($article->getInStock());

            try {
                $this->modelManager->persist($article);
                $this->modelManager->flush($article);
            } catch (Exception $e) {
                //
            }
        }

        return $orderDetail;
    }

    /**
     * Append internal comment on order
     *
     * @param Order $order
     * @param string $text
     *
     * @return Order $order;
     */
    public function appendInternalComment(Order $order, $text)
    {
        $comment = $order->getInternalComment();
        $comment = $comment . (strlen($comment) ? "\n\n" : "") . $text;

        return $order->setInternalComment($comment);
    }
}
