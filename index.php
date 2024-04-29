<?php

namespace App\Utils\External\Import\Eshop\Order;

use App\Entity\Country;
use App\Entity\Customer;
use App\Entity\EshopOrder;
use App\Entity\EshopOrderLine;
use App\Entity\Product;
use App\Entity\ProductSeries;
use App\Enum\EshopOrderStatus;
use App\Model\Eshop\Customer\CustomerModel;
use App\Model\Eshop\Order\EshopOrderModel;
use App\Utils\External\Import\Provider\ProductSeriesProvider;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class EshopOrderImporter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface        $documentImportLogger,
        private ProductSeriesProvider  $productSeriesProvider,
    )
    {
    }

    public function import(array $eshopOrderModels, string $countryCode): void
    {
        $country = $this->entityManager->getRepository(Country::class)->findOneBy(['code' => $countryCode]);
        $this->entityManager->beginTransaction();

        try {
            $productCodes = [];

            foreach ($eshopOrderModels as $orderModel) {
                foreach ($orderModel->getEshopOrderLineModels() as $lineModel) {
                    $productCodes[] = $lineModel->getProductCode();
                }
            }

            $productCodes = array_unique($productCodes);

            $customerIds = array_unique(array_map(fn($model) => $model->getCustomerModel()->getOxidId(), $eshopOrderModels));

            $products = $this->findProducts($productCodes);
            $customers = $this->findCustomers($customerIds);

            foreach ($eshopOrderModels as $eshopOrderModel) {
                $this->handleOrderAndLines($eshopOrderModel, $country, $products, $customers);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->documentImportLogger->error('Failed to import orders: ' . $e->getMessage());
            throw $e;
        }
    }

    private function findProducts(array $productCodes): array
    {
        $products = $this->entityManager->getRepository(Product::class)->findBy(['code' => $productCodes]);
        return array_reduce($products, function ($acc, $product) {
            $acc[$product->getCode()] = $product;
            return $acc;
        }, []);
    }

    private function findCustomers(array $customerIds): array
    {
        $customers = $this->entityManager->getRepository(Customer::class)->findBy(['oxidId' => $customerIds]);
        return array_reduce($customers, function ($acc, $customer) {
            $acc[$customer->getOxidId()] = $customer;
            return $acc;
        }, []);
    }

    private function handleOrderAndLines(EshopOrderModel $eshopOrderModel, Country $country, array $products, array $customers): void
    {
        $order = $this->entityManager->getRepository(EshopOrder::class)->findOneBy(['orderId' => $eshopOrderModel->getOrderId()]);

        if (!$order) {
            $order = new EshopOrder();
            $order->setOrderId($eshopOrderModel->getOrderId());
            $order->setExtendedStatus($eshopOrderModel->getExtendedStatus());
            $order->setStatus(EshopOrderStatus::from($eshopOrderModel->getStatus()));
            $order->setDate($eshopOrderModel->getDate());
            $customerModel = $eshopOrderModel->getCustomerModel();
            $customer = $customers[$customerModel->getOxidId()] ?? $this->createCustomer($customerModel);
            $order->setCustomer($customer);
            $this->entityManager->persist($order);
        }

        $order->setInvoiceNumber($eshopOrderModel->getInvoiceNumber());
        $order->setCountry($country);

        foreach ($eshopOrderModel->getEshopOrderLineModels() as $lineModel) {
            $product = $products[$lineModel->getProductCode()] ?? null;
            if (!$product) {
                continue;
            }

            $line = $this->entityManager->getRepository(EshopOrderLine::class)->findOneBy([
                'lineNumber' => $lineModel->getLineNumber(),
                'eshopOrder' => $order
            ]);

            if (!$line) {
                $line = new EshopOrderLine();
                $this->entityManager->persist($line);
            }
            $lineArray['Item_No'] = $lineModel->getProductCode();
            $productSeries = $this->getProductSeriesForLine($lineArray);
            $line->setProduct($product);
            $line->setQuantity($lineModel->getQuantity());
            $line->setPrice($lineModel->getPrice());
            $line->setUpdatedAt(new DateTime());
            $line->setLineNumber($lineModel->getLineNumber());
            $line->setProductName($lineModel->getProductName());
            $line->setDiscount($lineModel->getDiscount());
            $line->setProductSeries($productSeries);
            $line->setAmount($lineModel->getAmount());
            $line->setVat($lineModel->getVat());
            $line->setVatAmount($lineModel->getVatAmount());

            $order->addLine($line);
        }

        $this->entityManager->flush();
    }

    private function CreateCustomer(CustomerModel $customerModel): Customer
    {
        $customer = $this->entityManager->getRepository(Customer::class)->findOneBy(['oxidId' => $customerModel->getOxidId()]);

        if (!$customer) {
            $customer = new Customer();
            $customer->setOxidId($customerModel->getOxidId());
            $customer->setFirstName($customerModel->getFirstName());
            $customer->setLastName($customerModel->getLastname());
            $customer->setPhone($customerModel->getPhone());
            $customer->setEmail($customerModel->getEmail());
            $customer->setEnabled(1);
            $customer->setAddress($customerModel->getAddress());
            $customer->setCreatedAt(new DateTime());
            $customer->setUpdatedAt(new DateTime());
            $this->entityManager->persist($customer);
        }

        return $customer;
    }
    private function getProductSeriesForLine(array $line): ?ProductSeries
    {
        if ($this->productSeriesProvider->canBeSkipped($line)) {
            return null;
        }
        $this->productSeriesProvider->fetchLocalEntity($line);
        return $this->productSeriesProvider->getLocalEntity();
    }
}
