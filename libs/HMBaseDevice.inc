<?php
class HMBaseDevice extends HMBaseModule {
	
	function Create(){
		parent::Create();
       	$this->RegisterPropertyString("Address", "");
        $this->RegisterPropertyInteger("Protocol", 0);
		$this->RegisterAttributeString('DeviceDesc', '{}');
	}

	protected function ValidateConfig(){
		$this->UpdateDeviceDesc();	
    	return true;
    }
	
    protected function UpdateDeviceDesc(){
    	$device_desc = $this->GetDeviceDesc();
    	$address = $this->GetAddress();
    	$address_changed = empty($device_desc['ADDRESS']) || $device_desc['ADDRESS']!=$address;
    	if( $address_changed ){
    		if(empty($address)){
    			$device_desc=null;
    		}else{
    			$device_desc = $this->Call('getDeviceDescription',[$address]);
    			if(!$device_desc||empty($device_desc['TYPE']) || empty($device_desc['ADDRESS'])){
    				$device_desc=null;
    			}
    		}
     		$this->WriteAttributeString('DeviceDesc', json_encode($this->device_desc=$device_desc));
    	}
 		if($address_changed){
 			$this->AddressChanged($address);
    	}
    	return !is_null($device_desc);
    }

    protected function AddressChanged($Address){}
    protected function GetAddress(){
    	$address = $this->ReadPropertyString("Address");
   		return $address;
    }
    
	private $device_desc=null;
	protected function GetDeviceDesc(){
		if(is_null($this->device_desc))
			$this->device_desc = json_decode($this->ReadAttributeString('DeviceDesc'),true);
		return empty($this->device_desc['ADDRESS']) ? null: $this->device_desc;
	}
}

?>