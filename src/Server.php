<?php

/*
 * This file is part of the OpenStates Framework (osf) package.
 * (c) Guillaume Ponçon <guillaume.poncon@openstates.com>
 * For the full copyright and license information, please read the LICENSE file distributed with the project.
 */

namespace Osf\Irc;

/**
 * Simple IRC server using telnet as client
 * 
 * @author Guillaume Ponçon <guillaume.poncon@openstates.com>
 * @since 0.1 - 16 févr. 2006
 * @version 0.1 - January 24, 2018
 */
class Server {

    // Port and host of the server
    protected $ip; // = '127.0.0.1';
    protected $port = 9999;

    // Socket and resources to client
    protected $sock;
    protected $sockResources = [];
    
    // Pid of current, parent and childs processes
    protected $pid;
    protected $ppid = null;
    protected $pids =  [];

    // Descriptor for shared memory communication queue
    protected $qd = null;
    
    // Id of the current client if current instance is a client
    protected $currentId = 0;

    /**
     * Socket creation
     * @param string|null $ip
     * @throws Exception
     */
    public function __construct(?string $ip = null)
    {
        $this->ip = $ip ?? $this->ip ?? gethostname();
        if (!$this->sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) {
            throw new Exception("Unable to create a new socket!");
        }
        // socket_set_option($this->sock, SOL_SOCKET, SO_KEEPALIVE, 0);
        if (!socket_bind($this->sock, $this->ip, $this->port)) {
            $msg  = "Unable to connect from ";
            $msg .= $this->ip . " on port " . $this->port . ".";
            throw new Exception($msg);
        }
        if (!socket_listen($this->sock, 5)) {
            $msg  = "Unable to read from " . $this->ip;
            $msg .= " on port " . $this->port . ".";
            throw new Exception($msg);
        }
        $this->log("Socket attached to " . $this->ip . ' ' . $this->port);
        $this->log("In a terminal, type 'telnet " . $this->ip . ' ' . $this->port . "' for each client.");
    }

    /**
     * Cleaning
     */
    public function __destruct()
    {
        if ($this->ppid == $this->pid) {
            msg_remove_queue($this->qd);
            if ($this->sock) {
                socket_close($this->sock);
            }
            $this->log("Full stop");
        } else {
            $this->log("Stopping " . $this->pid);
        }
    }

    /**
     * Start server, clients and connexion manager
     * @return void
     */
    public function start(): void
    {
        $this->pid = posix_getpid();
        $this->ppid = $this->pid;

        $this->qd = msg_get_queue('123456', 0666);
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new Exception('Unable to create a child process for the queue server.');
        }

        // Parent: create clients
        else if ($pid) {
            $this->manageClients();
        }

        // Child: listen messages and dispatch it dispatch to clients
        else {
            $this->pid = posix_getpid();
            $this->manageMessages();
        }
    }
    
    /**
     * Manage message redistribution
     */
    protected function manageMessages(): void
    {
        $pids     = [];
        $pseudos  = [];
        $msgType  = null;
        $msg      = null;
        $msgError = null;
        $this->log("Starting the message server...");
        
        while (true) {
            
            // Wait for message reception and display errors
            $msg = null;
            if (!msg_receive($this->qd, $this->ppid, $msgType, 16384, $msg, true, 0, $msgError)) {
                $this->log("[ERR] Unable to receive message : " . $msgError);
                $this->log("[ERR] State : qd : $this->qd, ppid : $this->ppid, msgType : $msgType, msg : $msg.");
                break;
            } else if ($msg === '') {
                $this->log("[ERR] Empty message.");
                break;
            }

            // Reading and message decomposition
            $this->log("Receiving [$msgType] type message: " . $msg);
            $matches = null;
            if (!preg_match('/^([0-9]+)\|(.*)$/', $msg, $matches)) {
                $this->log('Bad message syntax!');
                continue;
            }
            [, $pid, $msgData] = $matches;

            // Empty message: continue
            if ($msgData === '') {
                continue;
            }
            
            // Commands management (connect, pseudo, quit, me)
            $isCmd = $msgData[0] == '/';
            $pseudo = null;
            if ($isCmd) {
                $matches = null;
                preg_match('#^/([^ ]+) ? *(.*?) *$#', $msgData, $matches);
                [, $command, $arg] = $matches;
                switch ($command) {

                    case 'connect' :
                        $this->log('Recording ' . $arg . ' from ' . $pid . ' in pids array.');
                        $pids[$pid] = (int) $arg;
                        $msgData = 'just joined us.';
                        break;

                    case 'pseudo' :
                        $this->log('Recording ' . $arg . ' from ' . $pid . ' in pseudonym array.');
                        $pseudo = trim($arg);
                        $pseudos[$pid] = $pseudo;
                        $msgData = 'is joining the irc channel...';
                        break;

                    case 'quit' :
                        $msgData = 'just left us.';
                        break;

                    case 'me' :
                        if (!$arg) {
                            continue 2;
                        }
                        $msgData = $arg;
                        break;

                    default :
                        $this->log("Command [" . $command . "] not found.");
                        continue 2;
                }
            }

            // If nobody, continue
            if (!$pids) {
                continue;
            }

            // Message broadcast
            $msgPattern = $isCmd ? '* %s %s' : '%s> %s';
            $pseudo = $pseudo ?? $pseudos[$pids[$pid]];
            $msgData = sprintf($msgPattern, $pseudo, $msgData) . "\n" . chr(13);
            $this->log('Broadcast: [' . trim($msgData) . ']');
            foreach ($pids as $clientPid) {
                if (!msg_send($this->qd, $clientPid, $msgData, true, true, $msgError)) {
                    $this->log("Unable to send a message: " . $msgError);
                }
            }
        }
    }
    
    /**
     * Creates clients one by one
     * @return void
     * @throws Exception
     */
    protected function manageClients(): void
    {
        // Wait for a new client
        $this->currentId++;
        $this->log("Client server is listening...");
        if (!$this->sockResources[$this->currentId] = socket_accept($this->sock)) {
            $this->log('Unsuccessful attempt to connect with a client.');
            return;
        }
        
        // New process creation for the client
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new Exception('Unable to create a child process for the new client.');
        }
        
        // Parent: register the new client pid and restart listening loop
        else if ($pid) {
            $this->pids[$pid] = true;
            $this->log("Démarrage d'une nouvelle instance (pid : " . $pid . "), attente d'un nouveau client...");
            $this->manageClients();
        } 
        
        // Child: starting a new client
        else {
            $this->pid = posix_getpid();
            $this->log('Start a session for the new client '.$this->currentId.'.');
            $this->startClient($this->currentId);
            $this->log('Process removal for client '.$this->currentId.' (serveur : '.$this->ppid.').');
            socket_close($this->sockResources[$this->currentId]);
        }
    }

    /**
     * Manage client processes.
     */
    protected function startClient(int $clientId): void
    {
        // Welcome message
        $msg = "\nWelcome on IrcServer!\n\n" . chr(13) . "Please enter a pseudo or the key: ";
        if (!socket_write($this->sockResources[$clientId], $msg, strlen($msg))) {
            $this->log('[ERR] Can not write to the client ' . $clientId . '!');
            return;
        }
        $rcv = '';
        $writer = false; // Writing mode, contains target window pid
        
        while (true) {
            
            // Reading the message
            $rcvPiece = (string) socket_read($this->sockResources[$clientId], 1024);
            if (!strlen($rcvPiece)) {
                return;
            }
            $rcv .= $rcvPiece;
            if ($rcvPiece[strlen($rcvPiece) - 1] != "\n") {
                continue;
            }
            
            $this->log('Message received by ' . $clientId . ': ' . $rcv);
            $msg = '';
            $rcv = trim($rcv);

            // Write mode: execution of orders in the writing process
            if ($writer) {
                $msgErr = null;

                // Exit command
                if ($rcv == '/quit' || $rcv == '/q' || $rcv == '/exit') {

                    // Send a shutdown message to the server process
                    if (!msg_send($this->qd, $this->ppid, $this->pid . '|/quit ', true, true, $msgErr)) {
                        $this->log("Unable to send shutdown message [pid:$this->pid] [ppid:$writer]");
                    }

                    // Shutdown message to the visualisation process
                    if (!msg_send($this->qd, $writer, '/kill', true, true, $msgErr)) {
                        $this->log("Unable to send a shutdown order to the target window: " . $msgErr);
                    }

                    // Shutdown message for the client write window
                    $msg = "\n" . chr(13) . "Inactive window. You can close your terminal.\n" . chr(13);
                    if (!socket_write($this->sockResources[$clientId], $msg, strlen($msg))) {
                        $this->log("Unable to send a disabled window message. (writer)");
                    }

                    break;
                }
                
                // Send the message to the server queue.
                if (!msg_send($this->qd, $this->ppid, $this->pid . '|' . $rcv, true, true, $msgErr)) {
                    $this->log("Unable to send a message to the server [pid:$this->pid] [ppid:$writer]");
                }
                $msg = "> ";
            }
            
            // Connexion mode: receive the pid (code)
            else if (is_numeric($rcv)) {
                $rcv = (int) $rcv;
                $msgErr = null;
                $msgType = null;

                // If the message is a valid pid, then send a /connect my_pid|target_pid in the server queue
                // to define the target_pid as receiver. Then, display a prompt and continue.
                if (isset($this->pids[$rcv]) && $this->pids[$rcv] === true) {
                    $writer = $rcv;
                    if (!msg_send($this->qd, $this->ppid, $this->pid . "|/connect " . $writer, true, true, $msgErr)) {
                        $this->log("Unable to send guest login: " . $msgErr);
                    }
                    $msg = "\nWrite your messages here (/q to quit) : \n" . chr(13) . "\n" . chr(13) . "> ";
                } else {
                    $msg = "\nThis code is not found [$rcv]. Please retry: ";
                }
            }
            
            // Connexion mode: pseudonym filling
            else if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]{2,25}$/i', $rcv)) {
                
                $msg_error = null;
                $msg = "\n    Thank you, your name is " . $rcv . "!\n" . chr(13);
                $msg .= "\nNow, open a new terminal with the same telnet command and type this key: ";
                $msg .= $this->pid . "\n" . chr(13);
                $msg .= "Don't close this window, it's your reading screen.\n\n" . chr(13);

                if (!msg_send($this->qd, $this->ppid, $this->pid . "|/pseudo " . $rcv, true, true, $msgErr)) {
                    $this->log("Unable to send your pseudo: " . $msgErr);
                }
                if (!socket_write($this->sockResources[$clientId], $msg, strlen($msg))) {
                    $this->log("Unable to send the code message to the client.");
                    break;
                }
                
                // This loop wait for messages of the current pid to send to the read window. 
                while (true) {
                    if (msg_receive($this->qd, $this->pid, $msgType, 16384, $msg, true, 0, $msg_error)) {
                        if ($msg == '/kill') {
                            $msg = "\n" . chr(13) . "Inactive window. You can close this terminal.\n" . chr(13);
                            if (!socket_write($this->sockResources[$clientId], $msg, strlen($msg))) {
                                $this->log("Unable to send the inactive window message (reader).");
                            }
                            break 2;
                        }
                        if (!socket_write($this->sockResources[$clientId], $msg, strlen($msg))) {
                            $this->log("Write error on client socket " . $this->pid);
                        }
                    }
                }

            }
            
            // Connexion mode: syntax error (this is not a PID or a pseudonym).
            else {
                $msg = "\nYour pseudonym syntax is not correct, \n" . chr(13);
                $msg .= "or your key is not available.\n" . chr(13);
                $msg .= "Please choose a simple name or retry filling the numeric key.\n\n" . chr(13);
                $msg .= "Key or pseudonym: ";
            }
            $rcv = '';

            // Send the message to the client socket if exists
            if ($msg) {
                if (!socket_write($this->sockResources[$clientId], $msg, strlen($msg))) {
                    continue;
                }
            }
        }
    }

    /**
     * Log and display 
     */
    protected function log(string $txt): void
    {
        $prefix = $this->pid === $this->ppid ? '+' : '-';
        $date = date('Y-m-d H:i:s');
        $pid = (int) $this->pid;
        printf("%s %s %' 6d -> %s\n", $prefix, $date, $pid, trim($txt));
    }

    /**
     * Process monitoring
     * @return void
     */
    public static function startMonitor(): void
    {
        while (1) {
            if (!function_exists('passthru')) {
                echo 'PHP can not execute shell command.';
                break;
            }
            passthru('clear ; ps auxf | grep run.php | grep -v grep');
            sleep(1);
        }
    }
}
