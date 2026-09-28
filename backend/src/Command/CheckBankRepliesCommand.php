<?php

namespace App\Command;

use App\Entity\Ticket;
use App\Repository\TicketRepository;
use App\Service\TicketValidationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Webklex\PHPIMAP\ClientManager;

#[AsCommand(
    name: 'app:check-bank-replies',
    description: 'Lit les réponses de la banque par IMAP et valide les tickets confirmés'
)]
class CheckBankRepliesCommand extends Command
{
    public function __construct(
        private ParameterBagInterface $parameterBag,
        private TicketRepository $ticketRepository,
        private TicketValidationService $validationService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $cm = new ClientManager();
            $client = $cm->make([
                'host'          => $this->parameterBag->get('imap_host'),
                'port'          => (int) $this->parameterBag->get('imap_port'),
                'encryption'    => $this->parameterBag->get('imap_encryption'),
                'validate_cert' => false, // certificat SSL non valide sur mail.madaozi.mg
                'protocol'      => 'imap',
                'username'      => $this->parameterBag->get('imap_username'),
                'password'      => $this->parameterBag->get('imap_password'),
            ]);

            $client->connect();
            $output->writeln(sprintf(
                '<info>Connecté à IMAP %s:%s (user: %s)</info>',
                $this->parameterBag->get('imap_host'),
                $this->parameterBag->get('imap_port'),
                $this->parameterBag->get('imap_username')
            ));

            $inbox = $client->getFolder('INBOX');
            $messages = $inbox->query()->unseen()->get();

            $output->writeln(sprintf('%d email(s) non lu(s) trouvé(s).', $messages->count()));

            foreach ($messages as $message) {
                $subject = (string) $message->getSubject();

                if (!preg_match('/#(\d+)/', $subject, $matches)) {
                    $output->writeln(sprintf('  → Sujet ignoré (pas de #ID) : %s', $subject));
                    continue;
                }

                $ticketId = (int) $matches[1];
                $ticket = $this->ticketRepository->find($ticketId);

                if (!$ticket) {
                    $output->writeln(sprintf('  → Ticket #%d introuvable en base.', $ticketId));
                    continue;
                }

                $statutAttendu = defined(Ticket::class . '::STATUT_ATTENTE_REPONSE_BANQUE')
                    ? Ticket::STATUT_ATTENTE_REPONSE_BANQUE
                    : 'En attente réponse banque';

                if ($ticket->getStatut() !== $statutAttendu) {
                    $output->writeln(sprintf(
                        '  → Ticket #%d : statut actuel "%s" (attendu "%s") — ignoré.',
                        $ticketId,
                        $ticket->getStatut(),
                        $statutAttendu
                    ));
                    continue;
                }

                $body = strip_tags((string) ($message->getTextBody() ?: $message->getHTMLBody()));

                if (preg_match('/\bok\b/i', $body) || preg_match('/\b(validé|valide|confirmé|confirme|approuvé)\b/i', $body)) {
                    $this->validationService->validate($ticket, 'banque (IMAP auto)');
                    $output->writeln(sprintf('<info>Ticket #%d validé automatiquement.</info>', $ticketId));
                } else {
                    $this->validationService->alertInterlocuteurAmbiguousReply($ticket, $body);
                    $output->writeln(sprintf(
                        '<comment>Ticket #%d : réponse ambiguë, interlocuteur alerté.</comment>',
                        $ticketId
                    ));
                }

                $message->setFlag('Seen');
            }

            $client->disconnect();
            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $output->writeln('<error>Erreur IMAP : ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}