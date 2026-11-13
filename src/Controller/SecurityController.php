<?php

namespace App\Controller;

use App\Entity\Trip;
use App\Entity\User;
use App\Form\RegistrationType;
use App\Repository\TripRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class SecurityController extends AbstractController
{
    use TargetPathTrait;

    #[Route('/connexion', name: 'app_login')]
    public function login(Request $request, AuthenticationUtils $authenticationUtils, TripRepository $trips): Response
    {
        $error = $authenticationUtils->getLastAuthenticationError();
        $booking = $this->bookingContext($request, $trips, null !== $error);

        if ($this->getUser()) {
            return $booking ? $this->redirect($booking['url']) : $this->redirectToRoute('app_home');
        }

        return $this->render('security/login.html.twig', [
            'error' => $error,
            'last_username' => $authenticationUtils->getLastUsername(),
            'booking' => $booking,
        ]);
    }

    #[Route('/deconnexion', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Cette route est interceptée par le pare-feu.');
    }

    /** Un seul compte, utilisable comme conducteur et comme passager. */
    #[Route('/inscription', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $entityManager, Security $security, TripRepository $trips): Response
    {
        $booking = $this->bookingContext($request, $trips);

        if ($this->getUser()) {
            return $booking ? $this->redirect($booking['url']) : $this->redirectToRoute('app_home');
        }

        $user = new User();
        $form = $this->createForm(RegistrationType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $entityManager->persist($user);
            $entityManager->flush();
            $security->login($user, 'form_login', 'main');
            $this->addFlash('success', 'Bienvenue ! Votre compte est créé : vous pouvez réserver et publier des trajets.');

            return $booking ? $this->redirect($booking['url']) : $this->redirectToRoute('app_home');
        }

        return $this->render('security/register.html.twig', ['form' => $form, 'booking' => $booking]);
    }

    /** Le retour ne peut viser qu'une portion existante, jamais une URL fournie par le client. */
    private function bookingContext(Request $request, TripRepository $trips, bool $retry = false): ?array
    {
        $session = $request->getSession();
        $previous = $session->get('booking_handoff');
        $parameters = array_intersect_key($request->query->all(), array_flip(['trajet', 'depart', 'arrivee']));
        if ([] === $parameters && $retry && $previous && $this->getTargetPath($session, 'main') === $previous['url']) {
            $parameters = $previous['parameters'];
        }

        if ($previous && $this->getTargetPath($session, 'main') === $previous['url']) {
            $this->removeTargetPath($session, 'main');
        }
        $session->remove('booking_handoff');

        if (3 !== \count($parameters) || array_filter($parameters, static fn ($id) => !\is_scalar($id) || !ctype_digit((string) $id))) {
            return null;
        }

        $trip = $trips->find((int) $parameters['trajet']);
        if (!$trip instanceof Trip) {
            return null;
        }

        $boarding = $alighting = null;
        foreach ($trip->getStops() as $stop) {
            if ($stop->getId() === (int) $parameters['depart']) {
                $boarding = $stop;
            }
            if ($stop->getId() === (int) $parameters['arrivee']) {
                $alighting = $stop;
            }
        }
        if (!$boarding || !$alighting || $boarding->getPosition() >= $alighting->getPosition()) {
            return null;
        }

        $url = $this->generateUrl('app_trip_show', ['id' => $trip->getId(), 'depart' => $boarding->getId(), 'arrivee' => $alighting->getId(), '_fragment' => 'reserver']);
        if (!$this->getUser()) {
            $this->saveTargetPath($session, 'main', $url);
            $session->set('booking_handoff', ['parameters' => $parameters, 'url' => $url]);
        }

        return ['parameters' => $parameters, 'url' => $url, 'trip' => $trip, 'boarding' => $boarding, 'alighting' => $alighting];
    }
}
