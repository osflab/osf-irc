# Telnet IRC Server

A simple IRC server which uses telnet as client.

* [Video: installation and use](https://www.youtube.com/watch?v=DPWG_JqfoI8) (fr)

## Requirements

* PHP7.1 or more
* The pcntl extension
* composer

Requirements installation on debian/ubuntu:

```bash
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update -y
sudo apt install php7.1-cli composer
```

## Installation


```bash
composer create-project --prefer-dist osflab/osf-irc osf-irc
```

## Usage

**To start the server:**

```bash
php ./osf-irc/bin/irc-run.php [host_or_ip]
```

Replace `[host_or_ip]` by the address of the network device to bind. If you do 
not specify this value, the server may bind to localhost, you will not be able 
to use it from a remote machine.

**For each client:**

* In a new terminal, type `telnet <hostname> 9999` (replace `<hostname>` by yours) 
* Enter your name + `[enter]`: a number is displayed
* Open a *new terminal* and type `telnet <hostname> 9999` again
* Enter the number

You can use the first terminal for reading and the second one to write. Repeat 
this for each client.

## Additional information

Originally, this component is a demonstration of developing a deamon with PHP, 
using the pcntl extension. It addresses process management concepts, semaphores 
and network sockets.
