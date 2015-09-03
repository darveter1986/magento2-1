<?php

namespace Signifyd\Connect\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use \Magento\Framework\ObjectManagerInterface;
use Psr\Log\LoggerInterface;
use Signifyd\Connect\Lib\SDK\Core\SignifydAPI;
use Signifyd\Connect\Lib\SDK\Core\SignifydSettings;
use Signifyd\Connect\Lib\SDK\Models\Address as SignifydAddress;
use Signifyd\Connect\Lib\SDK\Models\Card;
use Signifyd\Connect\Lib\SDK\Models\CaseModel;
use Signifyd\Connect\Lib\SDK\Models\Purchase;
use Signifyd\Connect\Lib\SDK\Models\Recipient;
use Signifyd\Connect\Lib\SDK\Models\UserAccount;

/**
 * Class PurchaseHelper
 * Handles the conversion from Magento Order to Signifyd Case and sends to Signifyd service.
 * @package Signifyd\Connect\Helper
 */
class PurchaseHelper
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;

    /**
     * @var \Signifyd\Connect\Lib\SDK\core\SignifydAPI
     */
    protected $_api;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager;

    public function __construct(
        ObjectManagerInterface $objectManager,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig
    )
    {
        $this->_logger = $logger;
        $this->_objectManager = $objectManager;
        try {
            $settings = new SignifydSettings();
            $settings->apiKey = $scopeConfig->getValue('signifyd/general/key');
            $this->_logger->info(json_encode($settings));
            $settings->logInfo = true;
            $settings->loggerInfo = function($message) { $this->_logger->info($message); };
            $settings->loggerError = function($message) { $this->_logger->error($message); };
            $settings->apiAddress = "https://app.staging.signifyd.com/v2";
            $this->_api = new SignifydAPI($settings);
            $this->_logger->info(json_encode($settings));
        } catch (\Exception $e) {
            $this->_logger->error($e);
        }

    }

    /**
     * @param $order Order
     * @return Purchase
     */
    private function makePurchase(Order $order)
    {
        $this->_logger->info("makePurchase");
        return new Purchase();
    }

    /**
     * @param $mageAddress Address
     * @return SignifydAddress
     */
    private function formatSignifydAddress($mageAddress)
    {
        $this->_logger->info("formatSignifydAddress");
        $address = new SignifydAddress();

        $address->streetAddress = $mageAddress->getStreet();
        $this->_logger->info("formatSignifydAddress s");
        $address->unit = null;

        $address->city = $mageAddress->getCity();

        $address->provinceCode = $mageAddress->getRegionCode();
        $address->postalCode = $mageAddress->getPostcode();
        $address->countryCode = $mageAddress->getCountryId();

        $address->latitude = null;
        $address->longitude = null;

        $this->_logger->info("/formatSignifydAddress");
        return $address;
    }

    /**
     * @param $order Order
     * @return Recipient|null
     */
    private function makeRecipient(Order $order)
    {
        $this->_logger->info("makeRecipient");
        $address = $order->getShippingAddress();

        if($address == null) return null;

        $recipient = new Recipient();
        $recipient->deliveryAddress = $this->formatSignifydAddress($address);
        $recipient->fullName = $address->getFirstname() . " " . $address->getLastname();
        $recipient->confirmationPhone = $address->getTelephone();
        $recipient->confirmationEmail = $address->getEmail();
        return $recipient;
    }

    /**
     * @param $order Order
     * @return Card|null
     */
    private function makeCardInfo(Order $order)
    {
        $this->_logger->info("makeCardInfo");
        $payment = $order->getPayment();
        $this->_logger->info($payment->convertToJson());
        if(!(is_subclass_of($payment->getMethodInstance(), '\Magento\Payment\Model\Method\Cc')))
        {
            return null;
        }

        $card = new Card();
        $card->cardholderName = $payment->getCcOwner();
        $card->last4 = $payment->getCcLast4();
        $card->expiryMonth = $payment->getCcExpMonth();
        $card->expiryYear = $payment->getCcExpYear();
        $card->hash = $payment->getCcNumberEnc();
        $card->bin = substr((string)$payment->getData('cc_number'), 0, 6);
        $card->billingAddress = $this->formatSignifydAddress($order->getBillingAddress());
        return $card;
    }

    /** Construct a user account blob
     * @param $order Order
     * @return UserAccount
     */
    private function makeUserAccount(Order $order)
    {
        $this->_logger->info("makeUserAccount");
        $user = new UserAccount();
        $user->emailAddress = $order->getCustomerEmail();
        $user->accountNumber = $order->getCustomerId();
        $user->phone = $order->getBillingAddress()->getTelephone();
        return $user;
    }

    /** Construct a new case object
     * @param $order Order
     * @return CaseModel
     */
    public function processOrderData($order)
    {
        $this->_logger->info("processOrderData");
        $case = new CaseModel();
        $case->card = $this->makeCardInfo($order);
        $case->purchase = $this->makePurchase($order);
        $case->recipient = $this->makeRecipient($order);
        $case->userAccount = $this->makeUserAccount($order);
        return $case;
    }

    public function createNewCase($order)
    {
        /** @var $case \Signifyd\Connect\Model\Casedata */
        $this->_logger->info("createNewCase");
        $case = $this->_objectManager->create('Signifyd\Connect\Model\Casedata');
        $this->_logger->info("createNewCase 1");
        $case->setId($order->getIncrementId()) // FILLER DATA. Webhooks not hooked in, so mostly irrelevant
             ->setSignifydStatus("PENDING")
             ->setCode("NA")
             ->setScore(500.0)
             ->setEntriesText("");
        $this->_logger->info("createNewCase 2");
        $this->_logger->info($case->convertToJson());
        $this->_logger->info("createNewCase 3");
        $case->save();
        $this->_logger->info("createNewCase 4");
    }

    public function postCaseToSignifyd($caseData)
    {
        $id = $this->_api->createCase($caseData);
        if($id) {
            $this->_logger->info("Case sent. Id is $id");
        } else
        {
            $this->_logger->info("Case failed to send.");
        }
    }
}
