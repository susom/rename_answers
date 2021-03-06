<?php

namespace Stanford\RenameAnswers;

require_once "emLoggerTrait.php";

/**
 * Class RenameAnswers
 * @package Stanford\RenameAnswers
 * @property array $instances;
 * @property array $dataDictionary;
 * @property string $destinationInstrument
 * @property string $sourceInstrument
 * @property \Project $project
 * @property boolean $isSourceInstrumentRepeatable
 * @property boolean $isDestinationInstrumentRepeatable
 * @property boolean $migrateStatus
 * @property int $recordInstance
 */
class RenameAnswers extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;

    private $instances;

    private $destinationInstrument;

    private $sourceInstrument;

    private $dataDictionary = array();

    private $project;

    private $isSourceInstrumentRepeatable;

    private $isDestinationInstrumentRepeatable;

    private $recordInstance;

    private $migrateStatus;

    public function __construct()
    {
        parent::__construct();
        // Other code to run when object is instantiated
        if ($_GET && $_GET['pid'] != null) {
            $this->setInstances();

            global $Proj;
            $this->setProject($Proj);

            $this->setDataDictionary(\REDCap::getDataDictionary($this->getProject()->project_id, 'array'));
        }
    }

    public function redcap_save_record(
        $project_id,
        $record = null,
        $instrument,
        int $event_id,
        int $group_id = null,
        string $survey_hash = null,
        int $response_id = null,
        int $repeat_instance = 1
    ) {
        try {
            if ($instance = $this->canCopyAnswers($instrument)) {
                # find destination instrument
                $this->setDestinationInstrument($instrument, $instance['source_suffix'],
                    $instance['destination_suffix']);

                $this->setRecordInstance($repeat_instance);

                $this->setMigrateStatus($instance['migrate_record_status']);

                $this->setIsDestinationInstrumentRepeatable($this->getProject()->isRepeatingForm($event_id,
                        $this->getDestinationInstrument()) || $this->getProject()->isRepeatingEvent($event_id));

                $this->setSourceInstrument($instrument);

                $this->setIsSourceInstrumentRepeatable($this->getProject()->isRepeatingForm($event_id,
                        $instrument) || $this->getProject()->isRepeatingEvent($event_id));

                // make sure the repeat status is the same between source and destination
                if ($this->isSourceInstrumentRepeatable() != $this->isDestinationInstrumentRepeatable()) {
                    throw new \LogicException("the repeat status for $instrument is " . $this->isSourceInstrumentRepeatable() . " while " . $this->getDestinationInstrument() . " status is " . $this->isDestinationInstrumentRepeatable());
                }

                $data = $this->convertSourceToDestinationData($record, $instrument, $event_id,
                    $instance['source_suffix'], $instance['destination_suffix']);
                $this->saveDestinationData($record, $data, $event_id, $instrument);
            }

        } catch (\LogicException $e) {
            \REDCap::logEvent("Error:" . $e->getMessage());
            $this->emError($e->getMessage());
        } catch (\Exception $e) {
            \REDCap::logEvent("Error:" . $e->getMessage());
            $this->emError($e->getMessage());
        }
    }

    /**
     * save data to destination
     * @param $record
     * @param $data
     * @param $eventId
     * @throws \Exception
     */
    private function saveDestinationData($record, $data, $eventId, $instrument)
    {
        $data[$this->getProject()->table_pk] = $record;
        $data['redcap_event_name'] = $this->getProject()->getUniqueEventNames($eventId);

        if ($this->isSourceInstrumentRepeatable()) {
            $data['redcap_repeat_instance'] = $this->getRecordInstance();
            if ($this->getProject()->isRepeatingForm($eventId, $instrument)) {
                $data['redcap_repeat_instrument'] = $this->getDestinationInstrument();
            }
        }

        $response = \REDCap::saveData($this->getProjectId(), 'json', json_encode(array($data)));
        if (!empty($response['errors'])) {
            if (is_array($response['errors'])) {
                throw new \Exception(implode(",", $response['errors']));
            } else {
                throw new \Exception($response['errors']);
            }

        } else {
            $this->emLog("Data copied from instrument " . $instrument . " to " . $this->getDestinationInstrument());
        }
    }

    private function convertSourceToDestinationData($recordId, $instrument, $eventId, $sourceSuffix, $destinationSuffix)
    {
        $param = array(
            'return_format' => 'array',
            'events' => $eventId
        );
        $result = array();
        $records = \REDCap::getData($param);

        foreach ($records as $id => $record) {
            if ($id == $recordId) {
                $fields = array_keys($this->getProject()->forms[$instrument]['fields']);
                //if instrument is repeatable then get the repeatable instance data instead of non-repeating
                if ($this->isDestinationInstrumentRepeatable()) {
                    $data = $record['repeat_instances'][$eventId][$instrument][$this->getRecordInstance()];

                    # if repeating on event as WHOLE !!!
                    if (is_null($data)) {
                        $temp = end($record['repeat_instances'][$eventId]);
                        $data = $temp[$this->getRecordInstance()];
                    }
                } else {
                    $data = $record[$eventId];
                }

                foreach ($data as $field => $value) {
                    if (in_array($field, $fields)) {
                        #migrate status if enabled from config.
                        if ($this->endsWith($field, '_complete') && $this->isMigrateStatus()) {
                            $temp = str_replace($sourceSuffix, '', $field);
                            $result[$temp] = $value;
                        } else {

                            $sourceProp = $this->getDataDictionaryProp($field);

                            $temp = $field;
                            if ($sourceSuffix != null) {
                                $temp = str_replace($sourceSuffix, '', $field);
                            }

                            if ($destinationSuffix != null) {
                                $temp = $temp . $destinationSuffix;
                            }
                            $newField = $temp;
                            $desFields = array_keys($this->getProject()->forms[$this->getDestinationInstrument()]['fields']);
                            if (in_array($newField, $desFields)) {

                                $destinationProp = $this->getDataDictionaryProp($newField);
                                // extra check to confirm the source and destination fields have the same datatype.
                                if ($destinationProp['field_type'] == $sourceProp['field_type']) {

                                    # special case for checkboxes
                                    if ($destinationProp['field_type'] == 'checkbox') {
                                        foreach ($value as $key => $item) {
                                            $result[$newField . '___' . $key] = $item;
                                        }
                                    } else {
                                        $result[$newField] = $value;
                                    }

                                } else {
                                    $this->emLog("$newField datatype " . $destinationProp['field_type'] . "  is not the same as  " . $field . " datatype " . $sourceProp['field_type'] . "!");
                                }

                            } else {
                                $this->emLog("$newField does not exist in " . $this->getDestinationInstrument() . " and it will be skipped!");
                            }
                        }
                    }
                }
                return $result;
            }
        }
        throw new \LogicException("source record was not found");
    }

    /**
     * @param string $instrument
     * @return array|bool
     */
    private function canCopyAnswers($instrument)
    {
        foreach ($this->getInstances() as $instance) {
            if ($instance['source_instruments'] == $instrument) {
                return $instance;
            }
        }
        return false;
    }

    /**
     * @return array
     */
    public function getInstances()
    {
        return $this->instances;
    }

    /**
     * @param array $instances
     */
    public function setInstances()
    {
        $this->instances = $this->getSubSettings('instance', $this->getProjectId());;
    }

    /**
     * @return string
     */
    public function getDestinationInstrument()
    {
        return $this->destinationInstrument;
    }

    /**
     * @param string $destinationInstrument
     */
    public function setDestinationInstrument($instrument, $sourceSuffix, $destinationSuffix)
    {
        if ($sourceSuffix != null) {
            $instrument = str_replace($sourceSuffix, '', $instrument);
        }

        if ($destinationSuffix != null) {
            $instrument = $instrument . $destinationSuffix;
        }
        $destinationInstrument = $instrument;
        # check if destination instrument exist first
        if (!isset($this->getProject()->forms[$destinationInstrument])) {
            throw new \LogicException("destination instrument does not exist");
        }
        $this->destinationInstrument = $destinationInstrument;
    }

    /**
     * @return \Project
     */
    public function getProject()
    {
        return $this->project;
    }

    /**
     * @param \Project $project
     */
    public function setProject(\Project $project)
    {
        $this->project = $project;
    }


    private function endsWith($haystack, $needle)
    {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }

        return (substr($haystack, -$length) === $needle);
    }

    /**
     * @return array
     */
    public function getDataDictionary()
    {
        return $this->dataDictionary;
    }

    /**
     * @param array $dataDictionary
     */
    public function setDataDictionary(array $dataDictionary)
    {
        $this->dataDictionary = $dataDictionary;
    }

    /**
     * @return array
     */
    public function getDataDictionaryProp($prop)
    {
        return $this->dataDictionary[$prop];
    }

    /**
     * @return bool
     */
    public function isSourceInstrumentRepeatable()
    {
        return $this->isSourceInstrumentRepeatable;
    }

    /**
     * @param bool $isSourceInstrumentRepeatable
     */
    public function setIsSourceInstrumentRepeatable($isSourceInstrumentRepeatable)
    {
        $this->isSourceInstrumentRepeatable = $isSourceInstrumentRepeatable;
    }

    /**
     * @return bool
     */
    public function isDestinationInstrumentRepeatable()
    {
        return $this->isDestinationInstrumentRepeatable;
    }

    /**
     * @param bool $isDestinationInstrumentRepeatable
     */
    public function setIsDestinationInstrumentRepeatable($isDestinationInstrumentRepeatable)
    {
        $this->isDestinationInstrumentRepeatable = $isDestinationInstrumentRepeatable;
    }

    /**
     * @return int
     */
    public function getRecordInstance()
    {
        return $this->recordInstance;
    }

    /**
     * @param int $recordInstance
     */
    public function setRecordInstance($recordInstance)
    {
        $this->recordInstance = $recordInstance;
    }

    /**
     * @return string
     */
    public function getSourceInstrument()
    {
        return $this->sourceInstrument;
    }

    /**
     * @param string $sourceInstrument
     */
    public function setSourceInstrument($sourceInstrument)
    {
        $this->sourceInstrument = $sourceInstrument;
    }

    /**
     * @return bool
     */
    public function isMigrateStatus()
    {
        return $this->migrateStatus;
    }

    /**
     * @param bool $migrateStatus
     */
    public function setMigrateStatus($migrateStatus)
    {
        $this->migrateStatus = $migrateStatus;
    }
}
