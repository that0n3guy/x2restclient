<?php namespace Oca\X2RestClient;

use Exception;
use GuzzleHttp\Client as GuzzleClient;
use HTMLPurifier, HTMLPurifier_Config;

class Client
{
    private $guzzle;

    public function __construct($base_url, $apiUser, $apiKey)
    {
        // just a guzzle config
        $config = array(
            'base_url' => $base_url,
            'defaults' => [
                'headers' => ['Content-Type' => 'application/json'],
                'auth' => [$apiUser, $apiKey],
            ],
        );
        $this->guzzle = new GuzzleClient($config);
    }

    public function createContact( $attributes, $mapper = null, $verfityDropdowns = true ){
        /**
         * @todo tracking key handling
         * @todo fingerprint handling
         * @todo may need to do htmlpurifier since x2engine does this on its own handleWebleadFormSubmission() https://laracasts.com/discuss/channels/tips/htmlpurifier-in-laravel-5 (1st comment)
         *   https://github.com/ezyang/htmlpurifier
         */

        $attributeInfo = $this->verifyAttributes('Contacts', $attributes, $verfityDropdowns = true);

        // verify we have all our needed "required" fields
        $requiredFields = $this->getRequiredFields('Contacts');
        foreach($requiredFields as $fieldName => $field){
            if ( !isset($attributeInfo['verifiedFields'][$fieldName]) )
                throw new Exception("Missing needed required field: '$fieldName'.");
        }

        // post it to x2engine
        $res = $this->guzzle->post( 'Contacts', ['body' => json_encode($attributeInfo['verifiedFields'])] );
        $contact = $res->json();
        if ( isset($contact['id']) ){
            return array('contact' => $contact, 'ignoredFields' => $attributeInfo['ignoredFields']);
        }

        throw new Exception("No contact ID returned.  Something must have gone wrong.");
    }

    public function updateContact($id, $attributes, $verifyDropdowns = true){
        /**
         * @todo tracking key handling
         * @todo fingerprint handling
         * @todo may need to do htmlpurifier since x2engine does this on its own handleWebleadFormSubmission() https://laracasts.com/discuss/channels/tips/htmlpurifier-in-laravel-5 (1st comment)
         *   https://github.com/ezyang/htmlpurifier
         */

        $attributeInfo = $this->verifyAttributes('Contacts', $attributes, $verifyDropdowns);

        // set dupecheck to zero
        if(!isset($attributeInfo['verifiedFields']['dupeCheck']))
            $attributeInfo['verifiedFields']['dupeCheck'] = 0;

        // post it to x2engine
        $res = $this->guzzle->put( 'Contacts/' . $id . '.json' , ['body' => json_encode($attributeInfo['verifiedFields'])] );
        $contact = $res->json();
        if ( isset($contact['id']) ){
            return array('contact' => $contact, 'ignoredFields' => $attributeInfo['ignoredFields']);
        }

        throw new Exception("No contact ID returned.  Something must have gone wrong.");
    }

    /**
     * @param $entity
     * @param $attributes
     * @param bool $verifyDropdowns
     * @return array
     */
    public function verifyAttributes($entity, $attributes, $verifyDropdowns = true){
        // get fieldnames to verify data
        $fieldNames = $this->getFields($entity, $verifyDropdowns);
        $verifiedFields = array();
        $ignoredFields = array();

        foreach($attributes as $key => $value){
            if(!empty($mapper) && isset($mapper[$key])){
                // check if the mapping was correct.
                $this->verifyGivenField($entity, $verifiedFields, $ignoredFields, $mapper[$key], $value, $fieldNames, $verifyDropdowns);
            }else{
                // No match in mapper, or mapper not provided assume it's a Contact attribute
                $this->verifyGivenField($entity, $verifiedFields, $ignoredFields, $key, $value, $fieldNames, $verifyDropdowns);
            }
        }

        return array(
            'verifiedFields' => $verifiedFields,
            'ignoredFields' => $ignoredFields,
            'fieldNames' => $fieldNames,
        );
    }

    /**
     * @param $fieldlist
     * @param $ignoredFields
     * @param $fieldName
     * @param $fieldValue
     * @param null $fieldNames
     * @param bool $verifyDropdowns, For this to work you must make sure $fieldNames includes dropdowns ( see getFields() )
     */
    public function verifyGivenField($entity, &$fieldlist, &$ignoredFields, $fieldName, $fieldValue, $fieldNames = null, $verifyDropdowns = false){
        if(empty($fieldNames)){
            $fieldNames = $this->getFields($entity, $verifyDropdowns);
        }
        // check if the mapping was correct.
        if(isset($fieldNames[$fieldName])){
            // verify the dropdown
            if( $verifyDropdowns && $fieldNames[$fieldName]['type'] == 'dropdown' && !isset($fieldNames[$fieldName]['dropdownInfo']['options'][$fieldValue]) ){
                $ignoredFields[$fieldName] = 'Not a valid dropdown value.';
            } else {
                $fieldlist[$fieldName] = $fieldValue;
            }
        } else {
            $ignoredFields[$fieldName] = 'Not a valid fieldname.';
//            throw new Exception($fieldName . ' is an invalid fieldName.');
        }
    }


    /**
     * Returns an array of contact's attributes given an email
     *
     * @param array $emails
     * @param bool $flatten, false if you want the returned array to include search field names as keys
     * @return array|null
     * @throws Exception
     */
    public function getContactsByEmails($emails, $flatten = true, $dedup = true){
        $emailFields = $this->getEmailFields('Contacts');
        if(is_array($emails)){
            $contacts = array();
            foreach ($emailFields as $field) {
                $contactList = $this->getEntityByEmailField('Contacts', $emails, $field['fieldName']);
                if($contactList){ // if null... do nothing
                    $contacts[$field['fieldName']] = $contactList;
                }
            }

            if($dedup){
                $dedup = array();
                foreach($contacts as $fname => $clists){
                    foreach($clists as $key => $contact){
                        if( isset($dedup[$contact['id']]) ){
                           unset($contacts[$fname][$key]);
                        }
                        $dedup[$contact['id']] = 1;
                    }
                }
            }

            // loop through all emails and email fields
            /*
             * below is NOT needed b/c submitting an array of emails acts like an "or"
            foreach($emails as $email){
                // lets try an "_or" search by all the different email fields
                foreach ($emailFields as $field) {
                    $check = $this->getContactsByEmail($email, $field);
                    if($check){
                        $contactslist[] = $check;
                    }
                }
            }
            */
            if($flatten){
                $contacts = $this->flattenEntityList($contacts);
            }
            return $contacts;
        } else {
            throw new Exception('$emails should be an array');
        }
    }

    /**
     * @param string $entity string
     * @param array|string $email
     * @param string $fieldName
     * @return mixed|null
     * @internal param string $field
     */
    public function getEntityByEmailField($entity, $email, $fieldName = 'email'){
        // limit to 500, probably never have 500 contacts when checking for duplicates... so if we receive 500, we know something is wrong
        $query = array('_limit' => 500, $fieldName => $email);
        $query = http_build_query($query);
        $res = $this->guzzle->get("$entity?$query");
        $contacts = $res->json();
        if (count($contacts) == 500 ) {
            return null; // something must have gone wrong.
        }
        return $contacts;
    }

    /**
     *
     * This is used if you make multiple queries to x2.  Each query can give you 1 or more entities.  This will flatten those list of queries.
     * See getContactsByEmails()
     *
     * @param $list, a list of lists.  something like [0=>[0=>[firstname, lastname,etc],1=>[firstname,lastname,etc]],1=>....]
     * @return array|null, returns a
     */
    public function flattenEntityList($list, $idkeys = false){
        if(!is_array($list)){
            return null;
        }

        $flat = array();
        foreach($list as $items) {
            foreach($items as $item){
                if($idkeys){
                    $flat[$item['id']] = $item;
                } else {
                    $flat[] = $item;
                }
            }
        }

        return $flat;
    }

    public function resetAllDupeCheck($entity, $list){
        if(!is_array($list))
            throw new Exception('$list should be an array');

        foreach($list as $item){
            if(!isset($item['id']))
                throw new Exception('$list items should contain an ID.');

            $this->resetDupeCheck($entity,$item['id']);
        }
    }

    public function resetDupeCheck($entity, $id){
        $config = array(
            'dupeCheck' => 0,
        );
        $res = $this->guzzle->put("$entity/$id.json", ['body' => json_encode($config)]);
        return $res->json();
    }

    public function getAllDropdowns($byId = true){
        $res = $this->guzzle->get('dropdowns');

        // return them with the dropdown ID as key in the array
        if($byId){
            $dropdowns = array();
            foreach($res->json() as $dropdown){
                $dropdowns[$dropdown['id']] = $dropdown;
            }
            return $dropdowns;
        }

        return $res->json();
    }

    public function getDropdown($fieldId){
        $res = $this->guzzle->get("dropdowns/$fieldId.json");
        return $res->json();
    }

    public function getEmailFields($entity){
        $fields = $this->getFields($entity);
        $emailFields = array();
        foreach($fields as $field){
            // check by name name
            if (strpos($field['fieldName'],'email') !== false) {
                $emailFields[$field['fieldName']] = $field;
                continue;
            }

            // check by type
            if ( $field['type'] == 'email' ){
                $emailFields[$field['fieldName']] = $field;
                continue;
            }
        }

        return $emailFields;
    }

    public function getRequiredFields($entity){
        $fields = $this->getFields($entity);
        $emailFields = array();
        foreach($fields as $field){
            // check by name name
            if ($field['required']) {
                $emailFields[$field['fieldName']] = $field;
            }
        }

        return $emailFields;
    }
    /**
     * @param $entity, type of entity (Contacts, Accounts, etc...)
     * @param $name, name of the field
     * @param string $nameType, could also be attributeLabel
     * @return null
     */
    public function getFieldByName($entity, $name, $nameType = 'fieldName'){
        $fields = $this->getFields($entity);
        foreach($fields as $field){
            if($field[$nameType] == $name){
                return $field;
            }
        }
        return null;
    }

    /**
     * @param $entity, entity type.
     * @param bool $withDropdownOptions, this will include the dropdown info per dropdown field.
     * @return array
     */
    public function getFields($entity, $withDropdownOptions = false){
        $res = $this->guzzle->get("$entity/fields");

        $data = array();
        if($withDropdownOptions){
            $dropdowns = $this->getAllDropdowns();
        }

        foreach ($res->json() as $field) {
            $data[$field['fieldName']] = $field;
            if($withDropdownOptions && $field['type'] == 'dropdown' && isset($dropdowns[$field['linkType']])){
                $data[$field['fieldName']]['dropdownInfo'] = $dropdowns[$field['linkType']];
            }
        }
        return $data;

    }
}
