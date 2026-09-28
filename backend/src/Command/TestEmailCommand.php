<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(name: 'app:test-email', description: 'Tester l\'envoi d\'email (client ou banque)')]
class TestEmailCommand extends Command
{
    public function __construct(
        private MailerInterface $mailer,
        private ParameterBagInterface $parameterBag
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'canal',
            InputArgument::OPTIONAL,
            'Canal à tester : client | banque',
            'client'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $canal = strtolower((string) $input->getArgument('canal'));

        try {
            if ($canal === 'banque') {
                $from = $this->parameterBag->has('bank_email_from')
                    ? $this->parameterBag->get('bank_email_from')
                    : 'remboursement@madaozi.mg';
                $reply = $this->parameterBag->has('bank_email_reply_to')
                    ? $this->parameterBag->get('bank_email_reply_to')
                    : $from;
                $to = $this->parameterBag->get('bank_email');
                $transport = 'banque';
            } else {
                $from = $this->parameterBag->has('client_email_from')
                    ? $this->parameterBag->get('client_email_from')
                    : $this->parameterBag->get('app_email_from');
                $reply = $this->parameterBag->has('client_email_reply_to')
                    ? $this->parameterBag->get('client_email_reply_to')
                    : $this->parameterBag->get('app_email_reply_to');
                $to = $this->parameterBag->get('bank_email');
                $transport = 'client';
            }

            $email = (new Email())
                ->from($from)
                ->to($to)
                ->replyTo($reply)
                ->subject(sprintf('Test Email [%s] - Système de remboursement', $transport))
                ->html(sprintf(
                    '<h2>✅ Test réussi (%s)</h2><p>Transport : <strong>%s</strong><br>From : %s</p>',
                    $transport,
                    $transport,
                    $from
                ));
            $email->getHeaders()->addTextHeader('X-Transport', $transport);

            $this->mailer->send($email);

            $output->writeln('✅ Email de test envoyé avec succès !');
            $output->writeln('📡 Transport : ' . $transport);
            $output->writeln('📧 De : ' . $from);
            $output->writeln('📧 À : ' . $to);
            $output->writeln('📧 Répondre à : ' . $reply);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('❌ Erreur : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}