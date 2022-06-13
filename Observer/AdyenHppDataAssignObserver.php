<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2020 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Observer;

use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\PaymentMethods\PaymentMethodFactory;
use Adyen\Payment\Helper\StateData;
use Adyen\Payment\Helper\Vault;
use Adyen\Payment\Model\ResourceModel\StateData\Collection;
use Adyen\Service\Validator\CheckoutStateDataValidator;
use Adyen\Service\Validator\DataArrayValidator;
use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Vault\Model\Ui\VaultConfigProvider;

class AdyenHppDataAssignObserver extends AbstractDataAssignObserver
{
    const BRAND_CODE = 'brand_code';
    const DF_VALUE = 'df_value';
    const GUEST_EMAIL = 'guestEmail';
    const STATE_DATA = 'stateData';

    /**
     * Approved root level keys from additional data array
     *
     * @var array
     */
    private static $approvedAdditionalDataKeys = [
        self::BRAND_CODE,
        self::DF_VALUE,
        self::GUEST_EMAIL,
        self::STATE_DATA
    ];

    /**
     * @var CheckoutStateDataValidator
     */
    protected $checkoutStateDataValidator;

    /**
     * @var Collection
     */
    protected $stateDataCollection;
    /**
     * @var StateData
     */
    private $stateData;

    /** @var PaymentMethodFactory */
    private $paymentMethodFactory;

    /** @var Vault  */
    private $vaultHelper;

    /**
     * AdyenHppDataAssignObserver constructor.
     *
     * @param CheckoutStateDataValidator $checkoutStateDataValidator
     * @param Collection $stateDataCollection
     * @param StateData $stateData
     * @param Session $checkoutSession
     * @param PaymentMethodFactory
     */
    public function __construct(
        CheckoutStateDataValidator $checkoutStateDataValidator,
        Collection $stateDataCollection,
        StateData $stateData,
        PaymentMethodFactory $paymentMethodFactory,
        Vault $vaultHelper
    ) {
        $this->checkoutStateDataValidator = $checkoutStateDataValidator;
        $this->stateDataCollection = $stateDataCollection;
        $this->stateData = $stateData;
        $this->paymentMethodFactory = $paymentMethodFactory;
        $this->vaultHelper = $vaultHelper;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $additionalDataToSave = [];
        // Get request fields
        $data = $this->readDataArgument($observer);
        $paymentInfo = $this->readPaymentModelArgument($observer);

        // Get additional data array
        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        if (!is_array($additionalData)) {
            return;
        }

        // Get a validated additional data array
        $additionalData = DataArrayValidator::getArrayOnlyWithApprovedKeys(
            $additionalData,
            self::$approvedAdditionalDataKeys
        );

        // JSON decode state data from the frontend or fetch it from the DB entity with the quote ID
        if (!empty($additionalData[self::STATE_DATA])) {
            $stateData = json_decode($additionalData[self::STATE_DATA], true);
        } else {
            $stateData = $this->stateDataCollection->getStateDataArrayWithQuoteId($paymentInfo->getData('quote_id'));
        }
        // Get validated state data array
        if (!empty($stateData)) {
            $stateData = $this->checkoutStateDataValidator->getValidatedAdditionalData($stateData);
            // Set stateData in a service and remove from payment's additionalData
            $this->stateData->setStateData($stateData, $paymentInfo->getData('quote_id'));
        }

        if (array_key_exists(self::BRAND_CODE, $additionalData) && $additionalData[self::BRAND_CODE] === Data::SEPA) {
            $additionalDataToSave = $this->getSepaAdditionalDataToSave($stateData);
        }


        unset($additionalData[self::STATE_DATA]);

        // Set additional data in the payment
        foreach (array_merge($additionalData, $additionalDataToSave) as $key => $data) {
            $paymentInfo->setAdditionalInformation($key, $data);
        }

        // Set ccType. If payment method is tokenizable, update additional information
        if (!empty($additionalData[self::BRAND_CODE])) {
            $paymentInfo->setCcType($additionalData[self::BRAND_CODE]);
            $adyenPaymentMethod = $this->paymentMethodFactory::createAdyenPaymentMethod($additionalData[self::BRAND_CODE]);
            if ($this->vaultHelper->allowRecurringOnPaymentMethod($adyenPaymentMethod)) {
                $paymentInfo->setAdditionalInformation(VaultConfigProvider::IS_ACTIVE_CODE, true);
            }
        }
    }

    /**
     * Get the additional data to save. This data will be required if the payment is to be tokenized
     *
     * @param array $stateData
     * @return array
     */
    private function getSepaAdditionalDataToSave(array $stateData): array
    {
        $additionalData = [];
        if (array_key_exists('iban', $stateData['paymentMethod'])) {
            $additionalData['iban'] = $stateData['paymentMethod']['iban'];
        }

        if (array_key_exists('ownerName', $stateData['paymentMethod'])) {
            $additionalData['ownerName'] = $stateData['paymentMethod']['ownerName'];
        }

        return $additionalData;
    }
}
