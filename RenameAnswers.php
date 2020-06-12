<?php
namespace Stanford\RenameAnswers;

require_once "emLoggerTrait.php";

/**
 * Class RenameAnswers
 * @package Stanford\RenameAnswers
 * @property array $instances;
 * @property string $destinationInstrument
 * @property \Project $project
 */
class RenameAnswers extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;

    private $instances;

    private $destinationInstrument;


    private $project;

    public function __construct()
    {
        parent::__construct();
        // Other code to run when object is instantiated
        if ($_GET && $_GET['pid'] != null) {
            $this->setInstances();

            global $Proj;
            $this->setProject($Proj);
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
                $data = $this->convertSourceToDestinationData($record, $instrument, $event_id,
                    $instance['source_suffix'], $instance['destination_suffix']);
                $this->saveDestinationData($record, $data, $event_id);
            }
        } catch (\LogicException $e) {
            echo $e->getMessage();
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
    }

    private function saveDestinationData($record, $data, $eventId)
    {
        $data[$this->getProject()->table_pk] = $record;
        $data['redcap_event_name'] = $this->getProject()->getUniqueEventNames($eventId);
        $response = \REDCap::saveData($this->getProjectId(), 'json', json_encode(array($data)));
        if (!empty($response['errors'])) {
            throw new \Exception(implode(",", $response['errors']));
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
                $data = $record[$eventId];
                foreach ($data as $field => $value) {
                    if (in_array($field, $fields)) {
                        #no need to migrate the status
                        if ($this->endsWith($field, '_complete')) {
                            continue;
                        }
                        if ($sourceSuffix != null) {
                            $field = str_replace($sourceSuffix, '', $field);
                        }

                        if ($destinationSuffix != null) {
                            $field = $field . $destinationSuffix;
                        }
                        $newField = $field;
                        $result[$newField] = $value;
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
}
