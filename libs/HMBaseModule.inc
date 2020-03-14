<?php
class HMBaseModule extends IPSModule {
	function Create(){
		parent::Create();
		$this->RegisterAttributeString('ConnectionUrl', '');
        $this->RegisterAttributeString('ScriptUrl', '');
        $this->RegisterAttributeString('AuthInfo', '');
 		$this->ConnectParent("{A151ECE9-D733-4FB9-AA15-7F7DD10C58AF}");
	}
	function ApplyChanges(){
		parent::ApplyChanges();
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
		$this->SetReceiveDataFilter('.*ABCDEFGH.*');
        
        if (IPS_GetKernelRunlevel() <> KR_READY) {
            return;
        }
        if($this->RegisterParent()){
        	$this->ValidateConfig();
        }
	}
    function MessageSink($TimeStamp, $SenderID, $Message, $Data){
        switch ($Message) {
        	case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;
            case FM_CONNECT:
            case IM_CHANGESETTINGS:
                $this->RegisterParent();
                if ($this->HasActiveParent()) {
                    $this->StateChanged(IS_ACTIVE);
                } else {
                    $this->StateChanged(IS_INACTIVE);
                }
                break;
            case FM_DISCONNECT:
                $this->RegisterParent();
                $this->StateChanged(IS_INACTIVE);
                break;
            case IM_CHANGESTATUS:
                    $this->StateChanged($Data[0]);
                break;
        }
    }
    protected function ValidateConfig(){
    	return true;
    }
    protected function StateChanged($State){}
	protected function KernelReady(){
    	$this->ApplyChanges();
    }

    
   	protected function RegisterParent(){
        $OldParentId = intval($this->GetBuffer('ConnectionID'));
        $ParentId = @IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($ParentId <> $OldParentId) {
            if ($OldParentId > 0) {
                $this->UnregisterMessage($OldParentId, IM_CHANGESETTINGS);
                $this->UnregisterMessage($OldParentId, IM_CHANGESTATUS);
            }
            if ($ParentId > 0) {
                $this->RegisterMessage($ParentId, IM_CHANGESETTINGS);
                $this->RegisterMessage($ParentId, IM_CHANGESTATUS);
            } else {
                $ParentId = 0;
            }
            $this->SetBuffer('ConnectionID',$ParentId);
        }
        $this->UpdateUrlsFromParentConfig($ParentId);
        return $ParentId;
    }
    
    protected final function GetConnectionUrl(){
    	return $this->ReadAttributeString('ConnectionUrl');
    }
    protected final function GetAuth(){
    	return $this->ReadAttributeString('AuthInfo');
    }
    protected final function GetScriptUrl(){
    	return $this->ReadAttributeString('ScriptUrl');
    }
    
    private function UpdateUrlsFromParentConfig($connectionID){
    	$auth = $scriptUrl= $conUrl='';
    	if($connectionID){
    		$ParentConfig = json_decode(IPS_GetConfiguration($connectionID), true);
    		$host   = $ParentConfig['Host'];
    		$conUrl = 'http://' . $host . ':' . (string)$ParentConfig['RFPort'] . '/' ;
            if (array_key_exists('UseSSL', $ParentConfig)) {
                if ($ParentConfig['UseSSL']) {
                    $scriptUrl = 'https://' . $host . ':' . (string)$ParentConfig['HSSSLPort'] . '/' ;
                    $conUrl = 'https://' . $host . ':' . (string)$ParentConfig['RFSSLPort'] . '/' ;
                 } else {
                    $scriptUrl ='http://' . $host . ':' . (string)$ParentConfig['HSPort'] . '/' ;
                }
            } else {
                if (array_key_exists('HSPort', $ParentConfig)) {
                    $scriptUrl ='http://' . $host . ':' . (string)$ParentConfig['HSPort'] . '/';
                } else {
                    $scriptUrl ='http://' . $host . ':8181/';
                }
            }
            if (array_key_exists('Username', $ParentConfig)) {
                if ($ParentConfig['Password'] != '') {
                    $auth = $ParentConfig['Username'] . ':' . $ParentConfig['Password'];
                }
            }
     	}
        $this->WriteAttributeString('ConnectionUrl', $conUrl);
        $this->WriteAttributeString('ScriptUrl', $scriptUrl);
        $this->WriteAttributeString('AuthInfo', $auth);
        $this->SendDebug(__FUNCTION__, "ConnectionUrl => ".$conUrl, 0);
        $this->SendDebug(__FUNCTION__, "Script    Url => ".$scriptUrl, 0);
        $this->SendDebug(__FUNCTION__, "Authinfo      => ".$auth, 0);
        
    }
    
}

?>