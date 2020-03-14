<?php
trait HMIPSReader {
	protected function Call(string $Method, $Arguments){
       if (!$this->HasActiveParent()) {
            trigger_error("Instance has no active Parent Instance!", E_USER_NOTICE);
            return null;
        }
        $ParentData = array(
            "DataID"     => "{75B6B237-A7B0-46B9-BBCE-8DF0CFE6FA52}",
            "Protocol"   => 0,//$this->ReadPropertyInteger('Protocol') ?? 0,
            "MethodName" => $Method,
            "WaitTime"   => 5000,
            "Data"       => $Arguments
        );
        $JSON = json_encode($ParentData);
        
        $this->SendDebug(__FUNCTION__, "Send => $Method ".json_encode($Arguments), 0);
        $ResultJSON = @$this->SendDataToParent($JSON);
        $Result = @json_decode($ResultJSON, true);
        if ($Result === false) {
            trigger_error('Error on Read Paramset', E_USER_NOTICE);
            $this->SendDebug(__FUNCTION__,'Error',0);
        }
        $this->SendDebug(__FUNCTION__,'Receive => '. json_encode($Result), 0);
        return $Result;
	}
}
?>