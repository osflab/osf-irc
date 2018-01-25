<?php

/*
 * This file is part of the OpenStates Framework (osf) package.
 * (c) @author Guillaume Ponçon <guillaume.poncon@openstates.com>
 * For the full copyright and license information, please read the LICENSE file distributed with the project.
 */

namespace Osf\Irc;

/**
 * IRC simplifié via telnet
 * 
 * @author Guillaume Ponçon <guillaume.poncon@openstates.com>
 * @since 0.1 - 16 févr. 2006
 * @version 0.1 - January 24, 2018
 */
class Server {

    // Adresse et port sur lesquels le serveur est connecté
    protected $ip; // = '127.0.0.1';
    protected $port = 9999;

    // La socket et les ressources qui pointent vers les clients
    protected $sock;
    protected $sockResources = [];
    
    // Le pid du processus courant, du parent et des fils si on est dans les parents
    protected $pid;
    protected $ppid = null;
    protected $pids =  [];

    // Queue descriptor pour la file de communication en mémoire partagée
    protected $qd = null;
    
    // Id du client en cours si on est un client
    protected $currentId = 0;

    /**
     * Création de la socket
     * @throws Exception
     */
    public function __construct()
    {
        $this->ip = $this->ip ?? gethostname();
        if (!$this->sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) {
            throw new Exception("Impossible de créer une nouvelle socket !");
        }
        // socket_set_option($this->sock, SOL_SOCKET, SO_KEEPALIVE, 0);
        if (!socket_bind($this->sock, $this->ip, $this->port)) {
            $msg  = "Impossible d'établir la connexion depuis ";
            $msg .= $this->ip . " sur le port " . $this->port . ".";
            throw new Exception($msg);
        }
        if (!socket_listen($this->sock, 5)) {
            $msg  = "Impossible d'écouter depuis " . $this->ip;
            $msg .= " sur le port " . $this->port . ".";
            throw new Exception($msg);
        }
        $this->log("Socket attachée à " . $this->ip . ' ' . $this->port);
    }

    /**
     * Nettoyages
     */
    public function __destruct()
    {
        if ($this->ppid == $this->pid) {
            msg_remove_queue($this->qd);
            if ($this->sock) {
                socket_close($this->sock);
            }
            $this->log("Arrêt général");
        } else {
            $this->log("Arrêt de " . $this->pid);
        }
    }

    /**
     * Démarrage du serveur et des clients - gestionnaire de connexion / processus racine.
     * @return void
     */
    public function start(): void
    {
        $this->pid = posix_getpid();
        $this->ppid = $this->pid;

        $this->qd = msg_get_queue('123456', 0666);
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new Exception('Impossible de créer un processus fils pour le serveur de messages.');
        }

        // Père : s'occupe de créer les clients
        else if ($pid) {
            $this->manageClients();
        }

        // Fils : s'occupe d'écouter les messages pour les distribuer aux clients
        else {
            $this->pid = posix_getpid();
            $this->manageMessages();
        }
    }
    
    /**
     * Gère la redistribution des messages
     */
    protected function manageMessages(): void
    {
        $pids     = [];
        $pseudos  = [];
        $msgType  = null;
        $msg      = null;
        $msgError = null;
        $this->log("Demarrage du serveur de messages...");
        
        while (true) {
            
            // Attente de réception d'un message et affichage des erreurs
            $msg = null;
            if (!msg_receive($this->qd, $this->ppid, $msgType, 16384, $msg, true, 0, $msgError)) {
                $this->log("[ERR] Impossible de recevoir un message : " . $msgError);
                $this->log("[ERR] Etat : qd : $this->qd, ppid : $this->ppid, msgType : $msgType, msg : $msg.");
                break;
            } else if ($msg === '') {
                $this->log("[ERR] Message vide.");
                break;
            }

            // Lecture et décomposition du message envoyé
            $this->log("Reception d'un message de type [$msgType] : " . $msg);
            $matches = null;
            if (!preg_match('/^([0-9]+)\|(.*)$/', $msg, $matches)) {
                $this->log('Syntaxe du message incorrecte !');
                continue;
            }
            [, $pid, $msgData] = $matches;

            // Message vide : continue
            if ($msgData === '') {
                continue;
            }
            
            // Traitement des commandes (connect, pseudo, quit, me)
            $isCmd = $msgData[0] == '/';
            $pseudo = null;
            if ($isCmd) {
                $matches = null;
                preg_match('#^/([^ ]+) ? *(.*?) *$#', $msgData, $matches);
                [, $command, $arg] = $matches;
                switch ($command) {

                    case 'connect' :
                        $this->log('Enregistrement de ' . $arg . ' depuis ' . $pid . ' dans le tableau pids.');
                        $pids[$pid] = (int) $arg;
                        $msgData = 'vient de nous rejoindre.';
                        break;

                    case 'pseudo' :
                        $this->log('Enregistrement de ' . $arg . ' depuis ' . $pid . ' dans le tableau pseudos.');
                        $pseudo = trim($arg);
                        $pseudos[$pid] = $pseudo;
                        $msgData = 'est en train de nous rejoindre sur le canal...';
                        break;

                    case 'quit' :
                        $msgData = 'vient de nous quitter.';
                        break;

                    case 'me' :
                        if (!$arg) {
                            continue 2;
                        }
                        $msgData = $arg;
                        break;

                    default :
                        $this->log("Commande " . $command . " non gérée.");
                        continue 2;
                }
            }

            // S'il y a personne, on continue
            if (!$pids) {
                continue;
            }

            // Broadcast du message
            $msgPattern = $isCmd ? '* %s %s' : '%s> %s';
            $pseudo = $pseudo ?? $pseudos[$pids[$pid]];
            $msgData = sprintf($msgPattern, $pseudo, $msgData) . "\n" . chr(13);
            $this->log('Broadcast : [' . trim($msgData) . ']');
            foreach ($pids as $clientPid) {
                if (!msg_send($this->qd, $clientPid, $msgData, true, true, $msgError)) {
                    $this->log("Impossible d'envoyer un message : " . $msgError);
                }
            }
        }
    }
    
    /**
     * Crée les clients au fur et à mesure
     * @return void
     * @throws Exception
     */
    protected function manageClients(): void
    {
        // Attente d'un nouveau client
        $this->currentId++;
        $this->log("Serveur de connexion en écoute.");
        if (!$this->sockResources[$this->currentId] = socket_accept($this->sock)) {
            $this->log('Tentative infructueuse de connexion avec un client.');
            return;
        }
        
        // Création d'un nouveau processus pour notre client
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new Exception('Impossible de créer un processus fils pour un nouveau client.');
        }
        
        // Père : on enregistre le pid du nouveau client dans la liste des pids et on relance l'écoute
        else if ($pid) {
            $this->pids[$pid] = true;
            $this->log("Démarrage d'une nouvelle instance (pid : " . $pid . "), attente d'un nouveau client...");
            $this->manageClients();
        } 
        
        // Fils : on démarre un client
        else {
            $this->pid = posix_getpid();
            $this->log('Démarrage de la session pour le client '.$this->currentId.'.');
            $this->startClient($this->currentId);
            $this->log('Suppression du processus du client '.$this->currentId.' (serveur : '.$this->ppid.').');
            socket_close($this->sockResources[$this->currentId]);
        }
    }

    /**
     * Gère un processus client.
     */
    protected function startClient(int $clientId): void
    {
        // Message de bienvenue
        $msg = "Bienvenue sur IrcServer !\n\n" . chr(13) . "Entrez un pseudo ou un code : ";
        if (!socket_write($this->sockResources[$clientId], $msg, strlen($msg))) {
            $this->log('[ERR] Ecriture impossible sur client ' . $clientId . ' !');
            return;
        }
        $rcv = '';
        $writer = false; // Est en mode écriture, contient le pid de la fenetre cible
        
        while (true) {
            
            // Lecture du message (tant qu'on à par reçu, on lit...)
            $rcvPiece = (string) socket_read($this->sockResources[$clientId], 1024);
            if (!strlen($rcvPiece)) {
                return;
            }
            $rcv .= $rcvPiece;
            if ($rcvPiece[strlen($rcvPiece) - 1] != "\n") {
                continue;
            }
            
            $this->log('Message reçu par ' . $clientId . ' : ' . $rcv);
            $msg = '';
            $rcv = trim($rcv);

            // Mode écriture : on interprête les ordres de la fenêtre d'écriture
            if ($writer) {
                $msgErr = null;

                // Si le message demande à quitter
                if ($rcv == '/quit' || $rcv == '/q' || $rcv == '/exit') {

                    // Envoi du message d'arret au serveur de messages
                    if (!msg_send($this->qd, $this->ppid, $this->pid . '|/quit ', true, true, $msgErr)) {
                        $this->log("Impossible d'envoyer l'ordre d'arrêt au serveur [pid:$this->pid] [ppid:$writer]");
                    }

                    // Transfert un ordre d'arret au process de visu
                    if (!msg_send($this->qd, $writer, '/kill', true, true, $msgErr)) {
                        $this->log("Impossible d'envoyer l'ordre d'arrêt à la fenêtre cible : " . $msgErr);
                    }

                    // Envoi d'un message d'arret à la console du client.
                    $msg = "\n" . chr(13) . "Fenêtre inactive. Vous pouvez fermer votre terminal.\n" . chr(13);
                    if (!socket_write($this->sockResources[$clientId], $msg, strlen($msg))) {
                        $this->log("Impossible d'envoyer le message de fenêtre inactive. (writer)");
                    }

                    break;
                }
                
                // Envoie le message dans la fil du serveur.
                if (!msg_send($this->qd, $this->ppid, $this->pid . '|' . $rcv, true, true, $msgErr)) {
                    $this->log("Impossible d'envoyer un message vers le serveur [pid:$this->pid] [ppid:$writer]");
                }
                $msg = "> ";
            }
            
            // Mode connexion : réception du PID (code)
            else if (is_numeric($rcv)) {
                $rcv = (int) $rcv;
                $msgErr = null;
                $msg_type = null;

                // Si le message est une clé (pid) valide, alors envoyer un /connect my_pid|pid_cible
                // dans la queue du serveur pour enregistrer le type $pid_cible comme receveur, puis
                // afficher un prompt et continuer. 
                if (isset($this->pids[$rcv]) && $this->pids[$rcv] === true) {
                    $writer = $rcv;
                    if (!msg_send($this->qd, $this->ppid, $this->pid . "|/connect " . $writer, true, true, $msgErr)) {
                        $this->log("Impossible d'envoyer l'invite de connexion : " . $msgErr);
                    }
                    $msg = "\nTapez vos messages ici (/quit pour quitter) : \n" . chr(13) . "\n" . chr(13) . "> ";
                } else {
                    $msg = "Le code n'est pas le bon [$rcv].\n" . chr(13);
                }
            }
            
            // Mode connexion : réception du pseudonyme
            else if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]{2,25}$/i', $rcv)) {
                
                $msg_error = null;
                $msg = "\n    Merci, votre pseudo est " . $rcv . " !\n" . chr(13);
                $msg .= "\nOuvrez un autre terminal et tapez le code suivant : ".$this->pid."\n".chr(13);
                $msg .= "Ne fermez pas cette fenêtre, c'est ici que la discussion s'affiche.\n\n".chr(13);

                if (!msg_send($this->qd, $this->ppid, $this->pid . "|/pseudo " . $rcv, true, true, $msgErr)) {
                    $this->log("Impossible d'envoyer le pseudo : " . $msgErr);
                }
                if (!socket_write($this->sockResources[$clientId], $msg, strlen($msg))) {
                    $this->log("Impossible d'envoyer le message du code au client.");
                    break;
                }
                
                // Boucle qui attend des messages sur le pid en cours et les envoie à la socket. (fenêtre de lecture)
                while (true) {
                    if (msg_receive($this->qd, $this->pid, $msg_type, 16384, $msg, true, 0, $msg_error)) {
                        if ($msg == '/kill') {
                            $msg = "\n" . chr(13) . "Fenêtre inactive. Vous pouvez fermer votre terminal.\n" . chr(13);
                            if (!socket_write($this->sockResources[$clientId], $msg, strlen($msg))) {
                                $this->log("Impossible d'envoyer le message de fenêtre inactive (reader).");
                            }
                            break 2;
                        }
                        if (!socket_write($this->sockResources[$clientId], $msg, strlen($msg))) {
                            $this->log("Erreur d'écriture sur la socket du client " . $this->pid);
                        }
                    }
                }

            }
            
            // Mode connexion : problème de syntaxe (ni un PID, ni un pseudo).
            else {
                $msg = "\nLa syntaxe du pseudonyme choisi n'est pas correcte, \n" . chr(13);
                $msg .= "ou le code n'est pas bon.\n" . chr(13);
                $msg .= "Veuillez choisir un nom simple ou resaisissez le code.\n\n" . chr(13);
                $msg .= "Code ou pseudonyme : ";
            }
            $rcv = '';

            // S'il y a un message à envoyer sur la socket du client, on l'envoie
            if ($msg) {
                if (!socket_write($this->sockResources[$clientId], $msg, strlen($msg))) {
                    continue;
                }
            }
        }
    }

    /**
     * Log et affichage. 
     */
    protected function log($txt): void
    {
        $prefix = $this->pid === $this->ppid ? '+' : '-';
        $date   = date('Y-m-d H:i:s');
        $pid    = (integer) $this->pid;
        printf("%s %s %' 6d -> %s\n", $prefix, $date, $pid, trim($txt));
    }

    /**
     * Monitoring des processus
     * @return void
     */
    public static function startMonitor(): void
    {
        while (1) {
            passthru('clear ; ps auxf | grep run.php | grep -v grep');
            sleep(1);
        }
    }
}
