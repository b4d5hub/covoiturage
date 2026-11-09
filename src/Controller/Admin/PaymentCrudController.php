<?php

namespace App\Controller\Admin;

use App\Entity\Payment;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class PaymentCrudController extends ReadOnlyCrudController
{
    public static function getEntityFqcn(): string
    {
        return Payment::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Paiement simulé')->setEntityLabelInPlural('Paiements simulés')->setDefaultSort(['id' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield Field::new('booking', 'Réservation')->formatValue(static fn ($booking): string => '#'.$booking->getId());
        yield MoneyField::new('amountCents', 'Montant')->setCurrency('MAD')->setStoredAsCents();
        yield TextField::new('statusLabel', 'État')->setSortable(false);
        yield DateTimeField::new('paidAt', 'Payé le');
        yield DateTimeField::new('refundedAt', 'Remboursé le');
    }
}
