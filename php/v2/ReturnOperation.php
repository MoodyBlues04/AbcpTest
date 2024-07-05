<?php

namespace NW\WebService\References\Operations\Notification;

class TsReturnOperation extends ReferencesOperation
{
    protected const TYPE_NEW = 1;
    protected const TYPE_CHANGE = 2;

    private array $requestData = [];
    private ?OperationResult $result = null;

    public function __construct()
    {
    }

    /**
     * @throws \Exception
     */
    public function doOperation(): array
    {
        $this->result = new OperationResult();
        $this->requestData = (array)$this->getRequest('data');

        $resellerId = (int)$this->getRequestDataOrFail('resellerId', 'Empty resellerId');
        $this->checkResellerExists($resellerId);

        $notificationType = $this->getNotificationType();

        $templateData = $this->getTemplateData($resellerId, $notificationType);
        $this->validateTemplateData($templateData);

        $emailFrom = getResellerEmailFrom($resellerId);
        $this->sendEmployeesMessages($emailFrom, $resellerId, $templateData);

        if ($this->shouldSendClientMessages($notificationType)) {
            $this->sendClientsMessages($emailFrom, $resellerId, $templateData);
        }

        return $this->result->toArray();
    }

    /**
     * @throws \Exception
     */
    private function getNotificationType(): int
    {
        return (int)$this->getRequestDataOrFail(
            'notificationType',
            'Empty notificationType'
        );
    }

    /**
     * @throws \Exception
     */
    private function checkResellerExists(int $resellerId): void
    {
        if (null === Seller::getById($resellerId)) {
            throw new \Exception('Seller not found!', 400);
        }
    }

    /**
     * @throws \Exception
     */
    private function getClient(int $resellerId): Contractor
    {
        $client = Contractor::getById((int)$this->getRequestData('clientId'));
        if (!$this->isValidClient($client, $resellerId)) {
            throw new \Exception('сlient not found!', 400);
        }
        return $client;
    }

    private function isValidClient(?Contractor $client, int $resellerId): bool
    {
        return !is_null($client) &&
            $client->type === Contractor::TYPE_CUSTOMER &&
            $client->Seller->id === $resellerId;
    }

    /**
     * @throws \Exception
     */
    private function getEmployee(int $employeeId): Contractor
    {
        // Если верить typehint-у others.php - null не может быть возвращен
        return Employee::getById($employeeId);
    }

    private function getDifferences(int $resellerId, int $notificationType): string
    {
        $differences = '';
        if ($notificationType === self::TYPE_NEW) {
            $differences = __('NewPositionAdded', null, $resellerId);
        } elseif ($notificationType === self::TYPE_CHANGE && !empty($this->getRequestData('differences'))) {
            $differences = __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int)$this->getRequestData('differences.from')),
                'TO' => Status::getName((int)$this->getRequestData('differences.to')),
            ], $resellerId);
        }
        return $differences;
    }

    /**
     * @throws \Exception
     */
    private function getTemplateData(int $resellerId, int $notificationType): array
    {
        $client = $this->getClient($resellerId);

        $creator = $this->getEmployee((int)$this->getRequestDataOrFail('creatorId'));
        $expert = $this->getEmployee((int)$this->getRequestDataOrFail('expertId'));

        $clientFullName = $client->getFullName() ?? $client->name;
        $differences = $this->getDifferences($resellerId, $notificationType);

        return [
            'COMPLAINT_ID' => (int)$this->getRequestDataOrFail('complaintId'),
            'COMPLAINT_NUMBER' => $this->getRequestDataOrFail('complaintNumber'),
            'CREATOR_ID' => (int)$this->getRequestDataOrFail('creatorId'),
            'CREATOR_NAME' => $creator->getFullName(),
            'EXPERT_ID' => (int)$this->getRequestDataOrFail('expertId'),
            'EXPERT_NAME' => $expert->getFullName(),
            'CLIENT_ID' => (int)$this->getRequestDataOrFail('clientId'),
            'CLIENT_NAME' => $clientFullName,
            'CONSUMPTION_ID' => (int)$this->getRequestDataOrFail('consumptionId'),
            'CONSUMPTION_NUMBER' => $this->getRequestDataOrFail('consumptionNumber'),
            'AGREEMENT_NUMBER' => $this->getRequestDataOrFail('agreementNumber'),
            'DATE' => $this->getRequestDataOrFail('date'),
            'DIFFERENCES' => $differences,
        ];
    }

    /**
     * @throws \Exception
     */
    private function validateTemplateData(array $templateData): void
    {
        foreach ($templateData as $key => $value) {
            if (empty($value)) {
                throw new \Exception("Template Data ({$key}) is empty!", 500);
            }
        }
    }

    private function sendEmployeesMessages(string $emailFrom, int $resellerId, array $templateData)
    {
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');
        if (empty($emailFrom) || count($emails) <= 0) {
            return;
        }

        foreach ($emails as $email) {
            $dataToSend = $this->getDataToSend($emailFrom,$email,'complaintEmployeeEmailSubject','complaintEmployeeEmailBody',$resellerId,$templateData);
            MessagesClient::sendMessage($dataToSend, $resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
            $this->result->isEmployeeEmailNotified = true;
        }
    }

    private function sendClientsMessages(string $emailFrom, int $resellerId, array $templateData): void
    {
        if (!empty($emailFrom) && !empty($client->email)) {
            $this->sendClientMessage($emailFrom, $client, $templateData, $resellerId);
        }
        if (!empty($client->mobile)) {
            $this->sendClientNotification($resellerId, $client->id, $templateData);
        }
    }

    private function sendClientMessage(string $emailFrom, Contractor $client, array $templateData, int $resellerId): void
    {
        $dataToSend = $this->getDataToSend($emailFrom, $client->email,'complaintClientEmailSubject','complaintClientEmailBody',$resellerId,$templateData);
        MessagesClient::sendMessage($dataToSend, $resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$this->getRequestData('differences.to'));
        $this->result->isClientEmailNotified = true;
    }

    private function sendClientNotification(int $resellerId, int $clientId, array $templateData): void
    {
        $error = '';
        $res = NotificationManager::send(
            $resellerId,
            $clientId,
            NotificationEvents::CHANGE_RETURN_STATUS,
            (int)$this->getRequestData('differences.to'),
            $templateData,
            $error
        );
        if ($res) {
            $this->result->isClientSmsSent = true;
        }
        if (!empty($error)) {
            $this->result->clientSmsMessage = $error;
        }
    }

    private function getDataToSend(string $emailFrom, string $emailTo, string $subject, string $message, int $resellerId, array $templateData): array
    {
        return [
            [
                'emailFrom' => $emailFrom,
                'emailTo' => $emailTo,
                'subject' => __($subject, $templateData, $resellerId),
                'message' => __($message, $templateData, $resellerId),
            ],
        ];
    }

    private function shouldSendClientMessages(int $notificationType): bool
    {
        return $notificationType === self::TYPE_CHANGE && !empty($this->getRequestData('differences.to'));
    }

    /**
     * @throws \Exception
     */
    private function getRequestDataOrFail(string $key, string $exceptionMessage = 'Illegal data key'): string
    {
        $val = $this->getRequestData($key);
        if (is_null($val)) {
            throw new \Exception($exceptionMessage);
        }
        return $val;
    }

    private function getRequestData(string $key, ?string $default = null): ?string
    {
        $keys = explode('.', $key);
        $data = $this->requestData;
        foreach ($keys as $key) {
            if (!$data[$key]) return $default;
            $data = $data[$key];
        }
        return $data;
    }
}

class OperationResult
{
    public bool $isClientSmsSent = false;
    public string $clientSmsMessage = '';
    public bool $isClientEmailNotified = false;
    public bool $isEmployeeEmailNotified = false;

    public function toArray(): array
    {
        return [
            'notificationEmployeeByEmail' => $this->isEmployeeEmailNotified,
            'notificationClientByEmail' => $this->isClientEmailNotified,
            'notificationClientBySms' => [
                'isSent' => $this->isClientSmsSent,
                'message' => $this->clientSmsMessage,
            ],
        ];
    }
}
