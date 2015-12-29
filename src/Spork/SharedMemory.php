<?php

/*
 * This file is part of Spork, an OpenSky project.
 *
 * (c) OpenSky Project Inc
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Spork;

use Spork\Exception\ProcessControlException;

/**
 * Sends messages between processes.
 */
class SharedMemory
{
    private $pid;
    private $ppid;
    private $signal;

    private $key;

    /**
     * Constructor.
     *
     * @param integer $pid    The child process id or null if this is the child
     * @param integer $signal The signal to send after writing to shared memory
     */
    public function __construct($pid = null, $signal = null)
    {
        if (null === $pid) {
            // child
            $pid   = posix_getpid();
            $ppid  = posix_getppid();
        } else {
            // parent
            $ppid  = null;
        }

        $this->pid  = $pid;
        $this->ppid = $ppid;
        $this->signal = $signal;

        $this->key = 'spork.'.$this->pid;
    }

    /**
     * Reads all messages from shared memory.
     *
     * @return array An array of messages
     */
    public function receive()
    {
        $messages = array();

        if (apcu_exists($this->key)) {
            $messages = apcu_fetch($this->key);
            apcu_delete($this->key);
        }

        return $messages;
    }

    /**
     * Writes a message to the shared memory.
     *
     * @param mixed   $message The message to send
     * @param integer $signal  The signal to send afterward
     * @param integer $pause   The number of microseconds to pause after signalling (default being 1/10 of a second)
     */
    public function send($message, $signal = null, $pause = 100000)
    {
        $messageArray = array();

        // Read any existing messages in apcu
        if(apcu_exists($this->key)){
            $readMessage = apcu_fetch($this->key);

            if($readMessage !== false){
                //$messageArray[] = unserialize($readMessage);
                $messageArray[] = $readMessage;
            }

            //cleanup
            apcu_delete($this->key);
        }else{

        }

        // Add the current message to the end of the array, and serialize it
        $messageArray[] = $message;

        // Write new serialized message to apcu
        $store = apcu_store($this->key, $messageArray, 0);

        if($store === false){
            throw new ProcessControlException(sprintf('Not able to create cache for PID: %s with key %s', $this->pid, $this->key));
        }

        if (false === $signal) {
            return;
        }

        $this->signal($signal ?: $this->signal);
        usleep($pause);
    }

    /**
     * Sends a signal to the other process.
     */
    public function signal($signal)
    {
        $pid = null === $this->ppid ? $this->pid : $this->ppid;

        return posix_kill($pid, $signal);
    }
}