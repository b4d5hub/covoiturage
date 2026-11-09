<?php

namespace App\Controller\Admin;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Notification;
use App\Entity\Payment;
use App\Entity\Review;
use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Time\MoroccoTime;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return $this->redirectToRoute('admin_trip_index');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('Covoiturage régional');
    }

    public function configureCrud(): Crud
    {
        return Crud::new()
            ->setTimezone(MoroccoTime::ZONE)
            ->setDateTimeFormat('dd/MM/yyyy HH:mm')
            ->setPaginatorPageSize(20);
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::section('Offre');
        yield MenuItem::linkTo(TripCrudController::class, 'Trajets', 'fa fa-route');
        yield MenuItem::linkTo(CityCrudController::class, 'Villes', 'fa fa-city');
        yield MenuItem::linkTo(VehicleCrudController::class, 'Véhicules', 'fa fa-car');
        yield MenuItem::section('Réservations');
        yield MenuItem::linkTo(BookingCrudController::class, 'Réservations', 'fa fa-ticket');
        yield MenuItem::linkTo(PaymentCrudController::class, 'Paiements simulés', 'fa fa-coins');
        yield MenuItem::section('Communauté');
        yield MenuItem::linkTo(UserCrudController::class, 'Comptes', 'fa fa-users');
        yield MenuItem::linkTo(ReviewCrudController::class, 'Avis', 'fa fa-star');
        yield MenuItem::linkTo(NotificationCrudController::class, 'Notifications', 'fa fa-bell');
    }
}
