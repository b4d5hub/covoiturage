<?php

namespace App\Controller;

use App\Entity\Trip;
use App\Entity\User;
use App\Exception\DomainRuleException;
use App\Form\ReviewType;
use App\Service\ReviewService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ReviewController extends AbstractController
{
    #[Route('/trajets/{id}/avis/{target}', name: 'app_review_new', requirements: ['id' => '\d+', 'target' => '\d+'])]
    public function new(Trip $trip, #[MapEntity(id: 'target')] User $target, Request $request, ReviewService $reviews): Response
    {
        $form = $this->createForm(ReviewType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $reviews->submit($trip, $this->getUser(), $target, $form->get('rating')->getData(), $form->get('comment')->getData());
                $this->addFlash('success', 'Merci, votre avis est publié sur le profil de '.$target->getDisplayName().'.');

                return $this->redirectToRoute($trip->getDriver() === $this->getUser() ? 'app_driver' : 'app_my_bookings');
            } catch (DomainRuleException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('review/new.html.twig', ['form' => $form, 'trip' => $trip, 'target' => $target], new Response(null, $form->isSubmitted() ? 422 : 200));
    }
}
