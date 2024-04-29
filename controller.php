<?php

declare(strict_types=1);

namespace App\Controller\Eshop;

use App\Entity\EshopOrder;
use App\Entity\EshopReturn;
use App\Enum\EshopReturnStatus;
use App\Enum\EurowebModuleClasses;
use App\Enum\Route as RouteEnum;
use App\Handler\Eshop\EshopReturnHandler;
use App\Repository\EshopOrderRepository;
use App\Repository\EshopReturnRepository;
use App\Utils\External\Model\Warehouse\Error;
use App\Utils\External\Model\Warehouse\PDF;
use App\Utils\Manager\WarehouseManager;
use DateTime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/eshop-return')]
class ReturnController extends AbstractController
{
    public function __construct(
        private readonly EshopReturnRepository $eshopReturnRepository,
        private readonly EshopReturnHandler $eshopReturnHandler,
        private readonly EshopOrderRepository $eshopOrderRepository,
        private readonly WarehouseManager $warehouseManager,
        private readonly SerializerInterface $serializer,
    ) {
    }

    #[Route('/create-from-id', RouteEnum::ESHOP_RETURN_CREATE_FROM_ID_VALUE, methods: ['POST'])]
    public function createFromId(Request $request): Response
    {
        $orderId = (string) $request->request->get('orderId');

        if (is_null($orderId)) {
            throw $this->createNotFoundException();
        }

        $eshopOrder = $this->eshopOrderRepository->findOneBy([
            'orderId' => $orderId,
        ]);

        if (!$eshopOrder instanceof EshopOrder) {
            throw $this->createNotFoundException();
        }

        $returnOrder = $this->eshopReturnRepository->findOneBy([
            'orderId' => $orderId,
        ]);

        if (!$returnOrder instanceof EshopReturn) {
            $returnOrder = new EshopReturn();
            $returnOrder->setOrderId($orderId);
            $returnOrder->setDate(new DateTime());
            $returnOrder->setCustomer($eshopOrder->getCustomer());
            $returnOrder->setStatus(EshopReturnStatus::UNCONFIRMED);
            $this->eshopReturnHandler->save($returnOrder);
        }

        return $this->redirectToRoute(
            RouteEnum::EUROWEB_MODULE_EDIT->value,
            [
                'className' => EurowebModuleClasses::ESHOP_RETURN->value,
                'id' => $returnOrder->getId(),
            ],
            Response::HTTP_SEE_OTHER
        );
    }

    #[Route('/stickers/create/{id}', RouteEnum::ESHOP_RETURN_STICKERS_CREATE_VALUE, methods: ['POST'])]
    public function createStickers(Request $request, EshopReturn $eshopReturn): Response
    {
        $boxCount = (int) $request->request->get('boxCount');

        if (is_null($boxCount) || $boxCount <= 0) {
            return new JsonResponse(['message' => 'Invalid box count number'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $stickersResponse = $this->warehouseManager->createStickers($eshopReturn, $boxCount);

        if ($stickersResponse instanceof Error) {
            return new JsonResponse(['message' => $stickersResponse->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([], Response::HTTP_OK);
    }

    #[Route('/stickers/print/{id}', RouteEnum::ESHOP_RETURN_STICKERS_PRINT_VALUE, methods: ['GET'])]
    public function printStickers(Request $request, EshopReturn $eshopReturn): Response
    {
        $manifestNumber = (string) $request->query->get('manifestNumber');

        if (is_null($manifestNumber)) {
            return new JsonResponse(['message' => 'Missing manifest number'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $stickersResponse = $this->warehouseManager->printStickers($eshopReturn, $manifestNumber);

        if ($stickersResponse instanceof Error) {
            return new JsonResponse(['message' => $stickersResponse->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$stickersResponse instanceof PDF) {
            return new JsonResponse(['message' => 'Something went wrong'], Response::HTTP_BAD_REQUEST);
        }

        return new Response(
            $stickersResponse->getContent(),
            Response::HTTP_OK,
            [
                'Content-Disposition' => 'inline; filename=' . $stickersResponse->getFileName(),
                'Content-Type' => 'application/pdf',
            ]
        );

        return $stickersResponse;
    }

    #[Route('/stickers/{id}', RouteEnum::ESHOP_RETURN_STICKERS_CANCEL_VALUE, methods: ['DELETE'])]
    public function cancelStickers(Request $request, EshopReturn $eshopReturn): Response
    {
        $manifestNumber = (string) $request->query->get('manifestNumber');

        if (is_null($manifestNumber)) {
            return new JsonResponse(['message' => 'Missing manifest number'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $stickersResponse = $this->warehouseManager->cancelStickers($eshopReturn, $manifestNumber);

        if ($stickersResponse instanceof Error) {
            return new JsonResponse(['message' => $stickersResponse->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([], Response::HTTP_OK);
    }

    #[Route('/stickers/{id}', RouteEnum::ESHOP_RETURN_STICKERS_VALUE, methods: ['GET'])]
    public function getStickers(EshopReturn $eshopReturn): Response
    {
        $stickers = $this->warehouseManager->getStickers($eshopReturn);

        if ($stickers instanceof Error) {
            return new JsonResponse(['message' => $stickers->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($stickers, Response::HTTP_OK);
    }
}
