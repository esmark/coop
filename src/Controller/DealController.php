<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Deal;
use App\Entity\Offer;
use App\Entity\Transaction;
use App\Event\DealEvent;
use App\Repository\DealRepository;
use App\Service\BillService;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class DealController extends AbstractController
{
    #[Route('/deals/', name: 'deals')]
    public function index(Request $request, DealRepository $dealRepo): Response
    {
        // @todo постраничность

        $show = $request->query->get('tab', 'active');

        if ($request->query->get('tab') === null or $request->query->get('tab') === 'new') {
            $deals = $dealRepo->findNewByUser($this->getUser());

            if ($deals) {
                $show = 'new';
            }
        }

        switch ($show) {
            case 'new':
                break;
            case 'active':
                $deals = $dealRepo->findActiveByUser($this->getUser());
                break;
            case 'in':
                $deals = $dealRepo->findActiveIncomingByUser($this->getUser());
                break;
            case 'out':
                $deals = $dealRepo->findActiveOutgoingByUser($this->getUser());
                break;
            case 'complete':
                $deals = $dealRepo->findCompleteByUser($this->getUser());
                break;
            case 'canceled':
                $deals = $dealRepo->findCanceledByUser($this->getUser());
                break;
            case 'all':
                $deals = $dealRepo->findAllByUser($this->getUser());
                break;
            default:
                throw $this->createNotFoundException('Unknown show type: '.$show);
        }

        return $this->render('deal/index.html.twig', [
            'show'          => $show,
            'pagerfanta'    => $deals,
        ]);
    }

    #[Route('/deal/create', name: 'deal_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em, BillService $billService, EventDispatcherInterface $dispatcher): JsonResponse
    {
        try {
            $offer = $em->find(Offer::class, $request->request->get('offer_id'));
        } catch (ConversionException $e) {
            return $this->json([
                'status' => 'error',
                'message' => 'Предложение не найдено',
            ]);
        }

        if (empty($offer)) {
            $data = [
                'status' => 'error',
                'message' => 'Предложение не найдено',
            ];
        } elseif ($offer->isDisabled()) {
            $data = [
                'status' => 'error',
                'message' => 'Предложение отключено',
            ];
        } else {
            $error_msg = null;
            $quantity = (int) $request->request->get('quantity', 1);
            $price    = (int) $request->request->get('price');
            $type     = (int) $request->request->get('type');

            if ($quantity < 1) {
                $quantity = 1;
            }

            if (!$offer->isStatusAccessToOrder()) {
                $error_msg = 'Предложение не доступно для заказа';
            } elseif (
                $type === Deal::TYPE_INNER
                and $quantity * $price > $billService->getBalance($this->getUser())
            ) {
                $error_msg = 'У вас недостаточно ресурсов для заключения сделки';
            } elseif (
                $offer->getQuantity()
                and $quantity > $offer->getQuantity() - (int) $offer->getQuantityReserved()
            ) {
                $error_msg = 'Количество не должно превышать имеющееся в наличии';
            } else {
                $isNew = false;
                $prevQuantity = false;
                $deal = $em->getRepository(Deal::class)->findOneBy([
                    'buyer' => $this->getUser(),
                    'offer' => $offer,
                    'status' => Deal::STATUS_NEW, // @todo возможно надо ещё и STATUS_VIEW
                ]);

                if (empty($deal)) {
                    $deal = new Deal();
                    $isNew = true;
                } else {
                    $prevQuantity = $deal->getQuantity();
                }

                $deal
                    ->setOffer($offer)
                    ->setCost($price)
                    ->setQuantity($quantity)
                    ->setActualCost($price)
                    ->setAmountCost($price * $quantity)
                    ->setBuyer($this->getUser())
                    ->setSeller($offer->getUser())
                    ->setType($type)
                    ->setComment($request->request->get('comment'))
                ;

                // @todo обработку ошибок при сохранении в БД.
                $em->persist($deal);
                $em->flush();

                $this->addFlash('success', 'Сделка добавлена'); // @todo remove

                if ($isNew) {
                    $dispatcher->dispatch($deal, DealEvent::CREATED);
                } elseif ($prevQuantity !== $deal->getQuantity()) {
                    $dispatcher->dispatch($deal, DealEvent::UPDATED);
                }

                $data = [
                    'status' => 'success',
                ];
            }

            if ($error_msg) {
                $data = [
                    'status' => 'error',
                    'message' => $error_msg,
                ];
            }
        }

        return $this->json($data);
    }

    #[Route('/deal/{id}', name: 'deal_show')]
    public function show(Deal $deal, Request $request, EntityManagerInterface $em, EventDispatcherInterface $dispatcher): Response
    {
        if ($deal->getSeller() == $this->getUser() or $deal->getBuyer() == $this->getUser()) {
            // Проверка на то, что сделка принадлежит отдному из аутентифицированных участников
        } else {
            return $this->redirectToRoute('deals');
        }

        $offer = $deal->getOffer();

        $quantity_reserved = (int) $offer->getQuantityReserved();

        if ($request->query->has('action')) {
            if ($request->query->get('action') === 'cancel') {
                if ($deal->getSeller() == $this->getUser()) {
                    $deal->setStatus(Deal::STATUS_CANCEL_BY_SELLER);

                    $em->persist($deal);
                    $em->flush();

                    $dispatcher->dispatch($deal, DealEvent::CANCELED_BY_SELLER);
                }

                if ($deal->getBuyer() == $this->getUser()) {
                    $deal->setStatus(Deal::STATUS_CANCEL_BY_BUYER);

                    $em->persist($deal);
                    $em->flush();

                    $dispatcher->dispatch($deal, DealEvent::CANCELED_BY_BUYER);
                }

                if (!empty($offer->getQuantity()) and $quantity_reserved > 0) {
                    $offer->setQuantityReserved($quantity_reserved - $deal->getQuantity());

                    if ($offer->getStatus() === Offer::STATUS_RESERVE) {
                        $offer->setStatus(Offer::STATUS_AVAILABLE);
                    }

                    $em->persist($offer);
                    $em->flush();
                }

                $this->addFlash('success', 'Сделка отменена.');
            }

            if ($request->query->get('action') === 'complete') {
                if ($deal->getBuyer() == $this->getUser()) {
                    if ($deal->getStatus() === Deal::STATUS_ACCEPTED) {
                        $deal->setStatus(Deal::STATUS_COMPLETE);
                    }

                    if ($deal->getStatus() === Deal::STATUS_ACCEPTED_EXTERNAL) {
                        $deal->setStatus(Deal::STATUS_COMPLETE_OUTSIDE);
                    }

                    $em->persist($deal);
                    $em->flush();

                    if (!empty($offer->getQuantity()) and $quantity_reserved > 0) {
                        $new_quantity_reserved = $quantity_reserved - $deal->getQuantity();
                        $new_quantity = $offer->getQuantity() - $deal->getQuantity();

                        $offer->setQuantityReserved(empty($new_quantity_reserved) ? null : $new_quantity_reserved);
                        $offer->setQuantity($new_quantity);

                        if ($new_quantity == 0 and empty($new_quantity_reserved)) {
                            $offer->setStatus(Offer::STATUS_NOT_AVAILABLE);
                        }

                        $em->persist($offer);
                        $em->flush();
                    }

                    // В транзакциях учитываются только сделки внутри системы.
                    if ($deal->getStatus() === Deal::STATUS_COMPLETE) {
                        $transaction = new Transaction();
                        $transaction
                            ->setFromUser($deal->getBuyer())
                            ->setToUser($deal->getSeller())
                            ->setSum($deal->getAmountCost())
                            ->setDeal($deal)
                            ->setComment('Успешное завершение сделки')
                        ;
                        $em->persist($transaction);
                        $em->flush();
                    }

                    $this->addFlash('success', 'Сделка завершена.');
                } else {
                    $this->addFlash('error', 'Вы не можете завершить сделку.');
                }
            }

            if ($request->query->get('action') === 'accept') {
                if ($deal->getType() === Deal::TYPE_INNER) {
                    $deal->setStatus(Deal::STATUS_ACCEPTED);
                } else {
                    $deal->setStatus(Deal::STATUS_ACCEPTED_EXTERNAL);
                }

                $offer->setQuantityReserved($deal->getQuantity());

                $em->persist($offer);
                $em->persist($deal);
                $em->flush();

                $this->addFlash('success', 'Сделка принята.');
            }

            return $this->redirectToRoute('deal_show', ['id' => $deal->getId()]);
        }

        if ($deal->getViewedAt() === null and $deal->getSeller() == $this->getUser()) {
            $deal
                ->setStatus(Deal::STATUS_VIEW)
                ->setViewedAt(new \DateTime())
            ;

            $em->persist($deal);
            $em->flush();
        }

        return $this->render('deal/show.html.twig', [
            'deal' => $deal,
        ]);
    }
}
