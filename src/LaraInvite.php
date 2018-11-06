<?php  namespace Junaidnasir\Larainvite;

use Exception;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Junaidnasir\Larainvite\Exceptions\InvalidTokenException;

/**
*   Laravel Invitation class
*/
class LaraInvite implements InvitationInterface
{

    /**
     * Email address to invite
     * @var string
     */
    private $email;

    /**
     * Message to add to the invite
     * @var string
     */
    private $message;

    /**
     * ID of the referred entity
     * @var string
     */
    private $entityId;

    /**
     * Referral Code for invitation
     * @var string
     */
    private $code = null;

    /**
     * Status of code existing in DB
     * @var bool
     */
    private $exist = false;

    /**
     * integer ID of referral
     * @var [type]
     */
    private $referral;

    /**
     * DateTime of referral code expiration
     * @var DateTime
     */
    private $expires;

    /**
     * Invitation Model
     * @var Junaidnasir\Larainvite\Models\LaraInviteModel
     */
    private $instance = null;
    
    /**
     * {@inheritdoc}
     */
    public function invite($email, $message, $entityId, $referral, $expires, $beforeSave = null)
    {
        $this->readyPayload($email, $message, $entityId, $referral, $expires)
             ->createInvite($beforeSave)
             ->publishEvent('invited');
        return $this->code;
    }
    
    /**
     * {@inheritdoc}
     */
    public function setCode($code)
    {
        $this->code = $code;
        try {
            $this->getModelInstance(false);
        } catch (InvalidTokenException $exception) {
            // handle invalid codes
            $this->exist = false;
        }
        return $this;
    }
    
    /**
     * {@inheritdoc}
     */
    public function get()
    {
        return $this->instance;
    }

    /**
     * {@inheritdoc}
     */
    public function status()
    {
        if ($this->isValid()) {
            return $this->instance->status;
        }
        return 'Invalid';
    }
    
    /**
     * {@inheritdoc}
     */
    public function consume()
    {
        if ($this->isValid()) {
            $this->instance->status = 'successful';
            $this->instance->save();
            $this->publishEvent('consumed');
            return true;
        }
        return false;
    }
    
    /**
     * {@inheritdoc}
     */
    public function cancel()
    {
        if ($this->isValid()) {
            $this->instance->status = 'canceled';
            $this->instance->save();
            $this->publishEvent('canceled');
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isExisting()
    {
        return $this->exist;
    }
    
    /**
     * {@inheritdoc}
     */
    public function isValid()
    {
        return (!$this->isExpired() && $this->isPending());
    }
    
    /**
     * {@inheritdoc}
     */
    public function isExpired()
    {
        if (!$this->isExisting()) {
            return true;
        }
        if (strtotime($this->instance->valid_till) >= time()) {
            return false;
        }
        $this->instance->status = 'expired';
        $this->instance->save();
        $this->publishEvent('expired');
        return true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function isPending()
    {
        if (!$this->isExisting()) {
            return false;
        }
        return ($this->instance->status == 'pending') ? true : false;
    }

    /**
     * {@inheritdoc}
     */
    public function isAllowed($email)
    {
        return ($this->isValid() && ($this->instance->email == $email));
    }
    
    /**
     * Fire junaidnasir.larainvite.invited again for the invitation
     * @return true
     */
    public function reminder()
    {
        Event::fire('junaidnasir.larainvite.invited', $this->instance, false);
        return true;
    }

    /**
     * generate invitation code and call save
     * @return self
     */
    private function createInvite($beforeSave = null)
    {
        $code = md5(uniqid());
        return $this->save($code, $beforeSave);
    }

    /**
     * saves invitation in DB
     * @param  string $code referral code
     * @return self
     */
    private function save($code, $beforeSave = null)
    {
        $this->getModelInstance();
        $this->instance->email      = $this->email;
        $this->instance->message    = $this->message;
        $this->instance->entityId   = $this->entityId;
        $this->instance->user_id    = $this->referral;
        $this->instance->valid_till = $this->expires;
        $this->instance->code       = $code;

        if (!is_null($beforeSave)) {
            if ($beforeSave instanceof Closure) {
                $beforeSave->call($this->instance);
            } elseif (is_callable($beforeSave)) {
                call_user_func($beforeSave, $this->instance);
            }
        }
        $this->instance->save();

        $this->code = $code;
        $this->exist = true;
        return $this;
    }

    /**
     * set $this->instance to Junaidnasir\Larainvite\Models\LaraInviteModel instance
     * @param  boolean $allowNew allow new model
     * @return self
     */
    private function getModelInstance($allowNew = true)
    {
        $model = config('larainvite.InvitationModel');
        //if (is_null($this->code) && $allowNew) {
        if ($allowNew) {
            $this->instance = new $model;
            return $this;
        }
        try {
            $this->instance = (new $model)->where('code', $this->code)->firstOrFail();
            $this->exist = true;
            return $this;
        } catch (ModelNotFoundException $e) {
            throw new InvalidTokenException("Invalid Token {$this->code}", 401);
        }
    }

    /**
     * set input variables
     * @param  string   $email    email to invite
     * @param  integer  $referral referral id
     * @param  DateTime $expires  expiration of token
     * @return self
     */
    private function readyPayload($email, $message, $entityId, $referral, $expires)
    {
        $this->email    = $email;
        $this->message  = $message;
        $this->entityId = $entityId;
        $this->referral = $referral;
        $this->expires  = $expires;
        return $this;
    }

    /**
     * Fire Laravel event
     * @param  string $event event name
     * @return self
     */
    private function publishEvent($event)
    {
        Event::fire('junaidnasir.larainvite.'.$event, $this->instance, false);
        return $this;
    }
}
