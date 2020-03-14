<?php
require_once __DIR__ . '/../libs/loader.inc';

class HMMonitor extends HMBaseModule{
	use HMIPSReader,HMScript;
	function Create(){
		parent::Create();
		foreach(self::EVENT_FIELDS as $index=>$var_name){
			$this->RegisterPropertyBoolean($var_name.'_Monitor', $index<3);
			$this->RegisterPropertyInteger($var_name.'_Script', 0);
		}
		$this->RegisterPropertyInteger('GLOBAL_Script', 0);
 		$this->RegisterPropertyInteger('MaxCallsPerEvent', 10);
		
		$this->RegisterPropertyInteger('RefreshRate', 0);
		
		$this->RegisterPropertyBoolean('Show_LastEvent', true);
		$this->RegisterPropertyBoolean('Show_Log', false);
		$this->RegisterPropertyBoolean('UseColorsInList', true);
		$this->RegisterPropertyInteger('MaxLogEntrys', 20);
// 		$this->RegisterPropertyString('LogFields','');

		$this->RegisterPropertyInteger('CleanUpDays', 14);
		$this->RegisterPropertyBoolean('CleanUpStateOk', false);
		$this->RegisterPropertyBoolean('CleanUpOnlyNoError', true);
		$this->RegisterPropertyBoolean('LogErrorsOnly', true);
		$this->RegisterPropertyBoolean('CheckCCUDevicesOnStartup', true);
		
		$this->RegisterPropertyString('LogSortBy', 'i');
		$this->RegisterPropertyBoolean('LogErrorToMessageLog', false);
		
		
		$this->RegisterAttributeString('DeviceList', '[]');
		$this->SetBuffer('StartUp',1);
	}
	function ReceiveData($JSONString){
		$this->SendDebug(__FUNCTION__,$JSONString, 0);
 		$data = json_decode($JSONString);
 		$this->ProcessEvent($data->DeviceID, $data->VariableName, $data->VariableValue);
	}
	function GetConfigurationForm(){
		$form = json_decode ( file_get_contents ( __DIR__ . '/form.json' ) );
		if(empty($form))return; // Falls fehler im Formular sind
		$include = $this->GetMonitorProps ();
		$keys = array_map(function($i){return substr($i,0,2);},array_keys($include));
		$include = array_combine($keys, array_values($include));
		$max = 550;
		$sum = array_sum ( $include );
		$width = round ( $max / $sum ) . 'px';
		foreach ( $form->actions [0]->columns as $col ) {
			if (array_key_exists ( $col->name, $include )) {
				if (! $include [$col->name]) $col->visible = false;
				else $col->width = $width;
			}
		}
		if ($this->GetBuffer ( 'ExpandConfig' )) {
			$form->elements [0]->expanded = true;
			$form->elements [0]->items[0]->expanded = true;
			$this->SetBuffer ( 'ExpandConfig', 0 );
		}
		$emptySelect = $this->Translate("No Device selected !");
		$updateButtonPart=function($id, $ident , $confirm)use($form,$emptySelect){
			$item = $form->actions [1]->items [$id];
			$item->confirm = $this->Translate ( $confirm );
			if(strpos($ident,'ITEM'))
				$item->onClick = "if(empty(\$DeviceList['a']))echo '$emptySelect'; else IPS_RequestAction(\$id,'$ident', json_encode(\$DeviceList));";
			else $item->onClick = "IPS_RequestAction(\$id,'$ident','');";
		};
		$updateButtonPart(0,'REFRESH_ITEM',"Refresh Selected ?");
		$updateButtonPart(1,'REFRESH_ALL',"Refresh Devicelist ?");
		$updateButtonPart(2,'RESET_ITEM',"Reset Selected ?");
		$updateButtonPart(3,'RESET_ALL',"Reset Devicelist ?");
		$updateButtonPart(4,'COLLECT_ERRORS',"Request errors from CCU ?");
		$updateButtonPart(5,'DELETE_ITEM',"Delete Selected ?");
		$updateButtonPart(6,'DELETE_ALL',"Delete Devicelist ?");
		$updateScriptPart = function ($var_name, $partIndex, $isPart2) use ($form) {
			$part = &$form->elements [0]->items[0]->items[$partIndex]->items;
			$scriptID = $this->ReadPropertyInteger ( $var_name . '_Script' );
			$isnew = empty ( $scriptID ) || ! IPS_ScriptExists ( $scriptID );
			if ($isnew) {
				$item = [ 
					"type" => "Button",
					"caption" => 'new',
					"width" => "15px",
					"onClick" => "IPS_RequestAction(\$id,'NEW_SCRIPT','$var_name');",
					"confirm" => $this->Translate ( "Are you sure you want to create a new script ?" )
				];
			} else
				$item = [ 
					"type" => "OpenObjectButton",
					"caption" => 'edit',
					"width" => "15px",
					"objectID" => $scriptID
				];
			if ($isPart2) $part [] = $item;
			else array_splice ( $part, 1, null, [ 
				$item
			] );
		};
		$updateScriptPart ( 'GLOBAL',2, false );
		$updateScriptPart ( 'LOWBAT', 2, true );
		$updateScriptPart ( 'UNREACH', 3, false );
		$updateScriptPart ( 'ERROR', 3, true );
		$updateScriptPart ( 'CONFIG_PENDING', 4, false );
		$updateScriptPart ( 'UPDATE_PENDING', 4, true );
		$updateScriptPart ( 'MOTION', 5, false );
		$updateScriptPart ( 'STATE', 5, true );
 		
// 		$end=end($form->elements[0]->items[0]->items);
// 		$end->items[]=$this->FormHelperCreateHelpButton('EventHelp',"Monitor Events", ['Help one','Help 2']);
// 		$end=end($form->elements[0]->items[1]->items);
// 		$end->items[]=$this->FormHelperCreateHelpButton('Test2', "Log Output", ['Help one','Help 2']);
		
		
		$form->actions [0]->values = $this->DeviceListToFormListValues();
		return json_encode ( $form );
	}
	function RequestAction($Ident, $Value){
		$this->SendDebug ( __FUNCTION__, "[ $Ident , $Value ]", 0 );
		if ($Ident == 'DELETE_ALL') {
			$this->DeleteDeviceListItem('');
			return;
		} elseif ($Ident == 'DELETE_ITEM') {
			if($data = json_decode ( $Value )){
				$this->DeleteDeviceListItem($data->a);
			} else echo $this->Translate("No Device selected !");
			return;
			
		} elseif ($Ident == 'REFRESH_ALL' || $Ident == 'REFRESH_ITEM' || $Ident == 'RESET_ALL' || $Ident == 'RESET_ITEM') {
			$address = '';
			if ($Ident == 'REFRESH_ITEM' || $Ident == 'RESET_ITEM') {
				$data = json_decode ( $Value );
				if (empty ( $data )) {
					$this->SendDebug(__FUNCTION__, $this->Translate("No Device selected"),0);
					return;
				}
				$address = $data->a;
				$clean_address = ($pos=strpos($address,':')) ? substr($address,0,$pos) : $address;
			}
			
			$list = $this->LoadDeviceList();
			$changed = false;
			foreach ( $list as $item ) {
				if (empty ( $address ) || $clean_address == $item ->address) {
					if ($Ident == 'RESET_ITEM' || $Ident == 'RESET_ALL') {
						for($j = 0; $j < count ( self::EVENT_FIELDS ); $j ++) {
							$item->count [$j] = empty ( $item->values [$j] ) ? 0 : 1;
						}
						$changed = true;
					} else{
						if ($this->UpdateDeviceListItem( $item, false )) {
							if($address) $this->UpdateDeviceListItemName($item,$address);
						   	$changed = true;
						}
					}
					if (!empty ( $address ) ) break;
				}
			}
			if ($changed) {
				$this->SaveDeviceList($list,true);
			}
			return;
		} elseif ($Ident == 'NEW_SCRIPT') {
			$this->SendDebug ( __FUNCTION__, 'CreateScript: ' . $Value, 0 );
			$this->CreateEventScript ( $Value );
			return;
		} elseif ($Ident == 'COLLECT_ERRORS'){
			$this->CollectCCUErrors();
		}
// 		else $this->HelpHelperRequestAction($Ident, $Value);
	}

	protected function ValidateConfig(){
    	$valid = parent::ValidateConfig();
		$filter=[];
		foreach(self::EVENT_FIELDS as $var_name){
			if($this->ReadPropertyBoolean($var_name.'_Monitor')) $filter[]='.*"'.$var_name.'".*';
		}
		$this->SetReceiveDataFilter(implode('|',$filter));
		if(intval($this->GetBuffer('StartUp')) && $this->HasActiveParent()){
			$this->SetBuffer('StartUp',1);
			if($this->ReadPropertyBoolean('CheckCCUDevicesOnStartup')){
				$this->CollectErrorList();
			}
		}
    	$this->LogList();
       	return $valid;
    }
    protected function HasActiveParent(){
    	return parent::HasActiveParent();
    }
 
	// Device List
	private function LoadDeviceList(){
		if(is_null($this->DeviceList))$this->DeviceList=json_decode ( $this->ReadAttributeString ( 'DeviceList' ) );
		return $this->DeviceList;
	}
	private function SaveDeviceList(array $DeviceList, bool $SendList){
		$this->DeviceList=$DeviceList;
		$this->WriteAttributeString( 'DeviceList' , json_encode($DeviceList));
		if($SendList)$this->SendDeviceList($DeviceList);
	}
	private function SendDeviceList(array $DeviceList=null){
		$values = $this->DeviceListToFormListValues ( $DeviceList );
		$this->UpdateFormField ( 'DeviceList', 'values', json_encode ( $values ) );
		$this->LogList($values);
	}
	private function CleanUpDeviceListList(&$DeviceList){
		$days 			= $this->ReadPropertyInteger ( 'CleanUpDays' );
		$removeEmpty  	= $this->ReadPropertyBoolean('CleanUpStateOk');
		$onlyNoErrors 	= $this->ReadPropertyBoolean('CleanUpOnlyNoError');
		$days 		   *= 86400;
		$now 			= time ();
		$changed 		= false;
		foreach ( $DeviceList as $index => $item ) {
			$hasError  = ($onlyNoErrors || $removeEmpty) ? $this->HasDeviceListItemErrors($item):false;
			if ($days > 0) {
				$date_div = $now - max ( $item->last);
				if ($date_div >= $days && !$hasError) {
					$this->SendDebug ( __FUNCTION__, "Remove! Device older $days days => " . json_encode ( $item ), 0 );
					unset ( $DeviceList [$index] );
					$changed = true;
					continue;
				}
			}
			if( $removeEmpty && !$hasError) {
				$this->SendDebug ( __FUNCTION__, "Remove! Device has no Errors => " . json_encode ( $item ), 0 );
				unset ( $DeviceList [$index] );
				$changed = true;
				continue;
			}
		}
		if ($changed) $DeviceList = array_values ( $DeviceList );
		return $changed;
	}
	private function CollectErrorList(){
		$m = $this->Call('getServiceMessages', []);
		if(empty($m))return;
		$list = $this->LoadDeviceList();
		foreach($m as $info){
			list($address,$var_name,$var_value)= $info;
			$this->ProcessEvent($address, $var_name, $var_value,$list);
		}
		$this->SaveDeviceList($list, true);
	}
	
	
	private function REMOVE_CollectErrorList(){
		$devices=$this->HMListDevices();
		$list = $this->LoadDeviceList();
		$changed = $item=false;
		foreach($devices as $device){
			$clean_address = $device['address'];
			if($pos=strpos($device['address'],':')){
				$channel=substr($device['address'],$pos+1);
				$clean_address=substr($device['address'],0,$pos);
			} else $channel=0;
			if(is_null($item = $this->FindDeviceListItem($clean_address,false,$list))){
				$item=$this->NewDeviceListItem($clean_address,$channel,$device['name']);
				if($this->HasDeviceListItemErrors($item)){
					$list[]=$item;
					$changed=true;
				}
			}else{
				if($this->UpdateDeviceListItem($item, false)){
					$changed =true;
				}
			}
		}
		if($changed){
			$this->SaveDeviceList($list,true);
			if($item)$this->LogItem($item);
		}
		return $changed;
	}	
	// Device List Item
	private function NewDeviceListItem($Address,$Channel,$name=''){
		$item=new stdClass();
		$item->address=$Address;
		$item->channels=[$Channel];
		$item->name=$name; 
		$item->objIDs=[];
		$item->values=[]; 
		$max = count(self::EVENT_FIELDS);
		$item->values=array_fill(0, $max, 0);
		$item->count=array_fill(0, $max, 0);
		$item->last=array_fill(0, $max, 0);
		if(empty($name))$this->UpdateDeviceListItemName($item,"$Address:$Channel");
		$this->UpdateDeviceListItem($item, true);
		return $item;
	}
	private function FindDeviceListItem(string $Address,bool $returnIndex, $DeviceList=null){
		if(is_null($DeviceList))$DeviceList = $this->LoadDeviceList();
		if($pos=strpos($Address,':'))$Address = substr($Address,0,$pos) ;
		$found=array_filter($DeviceList ,function($i)use($Address){return $i->address==$Address;} );
		return empty($found) ? null : ($returnIndex?key($found):$DeviceList[key($found)]);
	}
    private function HasDeviceListItemErrors($DeviceListItem){
		$includes = $this->GetMonitorProps ();
		$v = [ ];
		foreach ( array_values($includes) as $index => $value ) {
			if ($value) {
				$v [] = $DeviceListItem->values[$index] ?? 0;
			}
		}
		return  array_sum ( $v ) ;
    }
	private function DeleteDeviceListItem(string $Address){
		if (empty($Address) ) {
			$this->SaveDeviceList([],true);
			$this->SendDebug ( __FUNCTION__, 'Devicelist => cleared', 0 );
			return ;
		}
		$device_list = $this->LoadDeviceList();
		if(!is_null($found = $this->FindDeviceListItem($Address,true,$device_list))){
			unset($device_list[$found]);
			$device_list=array_values($device_list);
			$this->SaveDeviceList($device_list,true);
			$this->SendDebug ( __FUNCTION__, "Devicelist => $Address deleted", 0 );
		}
	}
	private function UpdateDeviceListItem($DeviceListItem, bool $ResetCounter){
		$doUpdate=function($channel)use($DeviceListItem,$ResetCounter){
			$return = false;
			if ($paramSet = $this->Call ( 'getParamset', [ 
				$DeviceListItem->address . ':' . $channel,
				'VALUES'
			] )) {
				foreach ( self::EVENT_FIELDS as $index => $var_name ) {
					if (array_key_exists ( $var_name, $paramSet )) {
						$return = true;
						$oldVal = $DeviceListItem->values[$index] ?? null;
						$time = $DeviceListItem->last[$index] ?? 0;
						$value = $paramSet [$var_name];
						if ($oldVal != $value || $time < 100000) {
							$DeviceListItem->values[$index] = $value;
							$DeviceListItem->last[$index] = time ();
						}
						if ($ResetCounter) $DeviceListItem->count [$index] = 0;
					}
				}
			}
			return $return;
		};
		$return = $doUpdate(0);
 		if($this->ReadPropertyBoolean('MOTION_Monitor')||$this->ReadPropertyBoolean('STATE_Monitor')){
 			if($doUpdate(1))$return = true;
 		}
		return $return;
	}
	private function UpdateDeviceListItemName($DeviceListItem, string $Address=''){
		if (is_null ( $this->HMIDs )) {
			$ids = IPS_GetInstanceListByModuleID ( '{EE4A81C6-5C90-4DB7-AD2F-F6BBD521412E}' );
			foreach ( $ids as $id )
				$this->HMIDs [$id] = IPS_GetProperty ( $id, 'Address' );
		}
		foreach ( $this->HMIDs as $id => $hm_address ) {
			if (($Address && $hm_address == $Address) || (! $Address && strpos ( $hm_address, $DeviceListItem->address ) === false)) {
				$DeviceListItem->name = IPS_GetName ( $id );
				$channel  = explode ( ':', $hm_address )[1] ?? 0;
				if (! in_array ( $id, $DeviceListItem->channels )) $DeviceListItem->channels [] = (int)$channel;
				if (! in_array ( $id, $DeviceListItem->objIDs )) $DeviceListItem->objIDs [] = $id;
				return true;
			}
		}
		if($name_info=$this->HMGetDeviceNames(empty($Address)?  $DeviceListItem->address : $Address)){
			if($pos=strpos($name_info['name'],':'))$name_info['name']=substr($name_info['name'],0,$pos);
			if(!empty($name_info['name'])){
				$DeviceListItem->name = $name_info['name'];
				return true;
			}
		}
		return false;
	}
	private function GetDeviceListItemInfo($DeviceListItem, bool $AsFormListItem){
		
		$include=$this->GetMonitorProps();
		$noData=$this->Translate('no data');
		$itemStatus=[]; $firstError = $lastError= [];  $lastErrorTime=0;$firstErrorTime=time();
		foreach(self::EVENT_FIELDS as $index=>$var_name){
			if(!$include[$var_name]){
				continue;
			}
			$key = substr($var_name,0,2);
			if(empty($DeviceListItem->last[$index])){
				if($AsFormListItem)$itemStatus[$key]=$noData;
				continue;
			}
			$count = $DeviceListItem->count[$index] ?? 0;
			$value=$DeviceListItem->values[$index] ?? 0;
			$color='';
			$v=$this->ValueToDeviceListItemValue($key,$value,$color);
			
			if(empty($firstError) && $value){
				$firstError[$var_name]=$v;
				$firstErrorTime = $DeviceListItem->last[$index];
			}
			if(empty($lastError) && $value && $lastErrorTime < $DeviceListItem->last[$index] ){
				$lastError[$var_name]=$v;
				$lastErrorTime = $DeviceListItem->last[$index];
			}
			if($count>1) $v.= " ($count)";
			if($AsFormListItem || $value)
				$itemStatus[$AsFormListItem ? $key : $var_name] = $v;
			
			if($value && $AsFormListItem){
				$itemStatus['rowColor']= $color;
			}				
		}
		
		if($AsFormListItem) {
			$var_name=key($firstError);
			$itemStatus['a'] = $DeviceListItem->address.':'.implode(',',$DeviceListItem->channels);
			$itemStatus['n'] = $DeviceListItem->name;
			$itemStatus['i'] = Date($this->Translate(self::DATE_FORMAT),$firstErrorTime).' ';
			if($var_name) $itemStatus['i'] .= $this->Translate($var_name.'_NAME').' '.   array_pop($firstError);
			else $itemStatus['i'] .= $this->Translate('No Errors');
		}else{
			foreach($itemStatus as $var_name=>&$msg){
				$msg=$this->Translate($var_name.'_NAME').' '.$msg;
			}
			$var_name=key($lastError);
			if(count($itemStatus)==1)$itemStatus=[];
			$itemStatus=sprintf("%s (%s) %s",$DeviceListItem->name,$DeviceListItem->address.':'.implode(',',$DeviceListItem->channels), implode(',', $itemStatus));
			if($var_name)$itemStatus.=' '.$this->Translate('Last Event').' '.$this->Translate($var_name.'_NAME').' '.array_pop($lastError).' ';
			if(empty($lastErrorTime)){
				$lastErrorTime=time();
				$itemStatus.= $this->Translate('No Errors').' ';
			}
			$itemStatus.= Date($this->Translate(self::DATE_FORMAT),$lastErrorTime);
		}
		
		return $itemStatus;		
		
	}
	// Converting
	private function DeviceListToFormListValues(array $DeviceList = null){
		if(is_null($DeviceList))$DeviceList = $this->LoadDeviceList();
		$items=[];
		foreach($DeviceList as $item){
			$items[]=$this->GetDeviceListItemInfo($item,true);
		}
		return $items;
	}
	private function ValueToDeviceListItemValue(string $Key, $Value,string &$Color=''){
		
		switch($Key){
			case 'LO': 
				$v= $Value ? $this->Translate('low') : $this->Translate('ok'); 
				$Color='#FFFFC0';
				break;
			case 'ER': 
				$v= $Value ? $this->Translate(self::HM_ERRORS[$Value]??'Unknown') : $this->Translate('ok');
				$Color='#FFC0C0';
				break;
			case 'CO':;
			case 'UP': 
				$v= $Value ? $this->Translate('pending') : $this->Translate('no'); 
				$Color='#C0C0FF';
				break;
			case 'UN':
				$v= $Value ? $this->Translate('no') : $this->Translate('yes'); 
				$Color='#DFDFDF';
				break;
			case 'MO':
				$Color='#C0C0FF';
				$v= $Value ? $this->Translate('detected') : $this->Translate('no'); 
				break;
			case 'ST':
				$v= $Value ? $this->Translate('on') : $this->Translate('off'); 
				$Color = '#C0FFC0';
				break;
			default:
				$v= $Value ? $this->Translate('yes') : $this->Translate('no'); 
				$Color='#FFC0C0';
				break; 
		}
		return $v;
		
	}
	// Variables for Log and Event Info
	private function LogItem($DeviceListItem ){
		if (!$this->ReadPropertyBoolean ( 'Show_LastEvent' )) {
			if ($varId = @$this->GetIDForIdent ( 'LOGLAST' )) $this->UnregisterVariable ( 'LOGLAST' );
			return;
		}
// 		if(empty($DeviceListItem)){
// 			$DeviceListItem=json_decode($this->GetBuffer('LastLogItem'),true);
// 		} 
// 		else $this->SetBuffer('LastLogItem',json_encode($DeviceListItem));
		
		$info = empty($DeviceListItem) ? '' : $this->GetDeviceListItemInfo ( $DeviceListItem, false );
		if ($info  && ! ($varId = @$this->GetIDForIdent ( 'LOGLAST' ))) {
			$varId = $this->RegisterVariableString ( 'LOGLAST', $this->Translate ( 'Last Event' ), '', 0 );
		}
		if($info)SetValue ( $varId, $info );
	}
	private function LogList(array $FormlistValues=null){
		if(!$this->ReadPropertyBoolean('Show_Log')){
			if($varId=@$this->GetIDForIdent('LOGLIST'))	$this->UnregisterVariable('LOGLIST');
			return;
		}
		if(is_null($FormlistValues))$FormlistValues=$this->DeviceListToFormListValues();
		if(!empty($FormlistValues)){
			$max=$this->ReadPropertyInteger('MaxLogEntrys');
			$showColor = $this->ReadPropertyBoolean('UseColorsInList');
			$rows=[];$head='';
			$includes=$this->GetMonitorProps();
			$visibleFields = array_sum($includes);
			$fieldWidth = round(60 / $visibleFields);
			$data=['<td width="8%">'.$this->Translate('Address').'</td>'];
			$data[]='<td width="15%">'.$this->Translate('Name').'</td>';
			foreach($includes as $var_name=>$visible){
				if($visible){
					$data[]=sprintf('<td width="'. $fieldWidth. '%%">%s</td>',$this->Translate($var_name.'_NAME'));
					$includes[substr($var_name,0,2)]=true;	
				}
			}
			$data[]='<td ">'.$this->Translate('Last Event').'</td>';
			$head='<tr>'.implode($data).'</tr>';
			$format ='<td style="white-space: nowrap;">%s</td>';
			
			if($sortField = $this->ReadPropertyString('LogSortBy')){
//				if($sortField =='i'||$sortField =='a'||$sortField =='n' )
				usort($FormlistValues,function($a,$b)use($sortField){
					return strcasecmp($b[$sortField],$a[$sortField] );
				})
				;
			}
			foreach($FormlistValues as $count=>$item){
				$data=[sprintf($format,$item['a'])];
				$data[]=sprintf($format,$item['n']);
				foreach($item as $var_ident=>$var_value){
					if(!empty($includes[$var_ident]) ){
						$data[]=sprintf($format,$var_value);
					}
				}
				$data[]=sprintf($format,$item['i']);
				$color=!$showColor || empty($item['rowColor']) ? '': ' style="color:black;background-color: '.$item['rowColor']. ';"';
				$rows[]="<tr$color>".implode($data)."</tr>";
				if($max>0 && $count>$max){
					break;
				}
			}
			$table = '<table cellspacing="0" cellpadding="3" style="font-size:small;">'.$head.implode($rows).'</table>';
		} else $table='';
		if(!($varId=@$this->GetIDForIdent('LOGLIST'))){
			$varId=$this->RegisterVariableString('LOGLIST', $this->Translate('Monitoring protocol'),'~HTMLBox',1);				
		}
		SetValue($varId, $table);
		
	}
	// Handle Message Events from Parent Socket	
	private function ProcessEvent($address, $var_name, $var_value, array &$list=null){

		$nameIndex = array_search($var_name,self::EVENT_FIELDS);
		if($nameIndex===false){
			$this->SendDebug(__FUNCTION__, $var_name . ' not supported', 0);
			return;
		}
		if(!$this->ReadPropertyBoolean($var_name.'_Monitor') ) {
			$this->SendDebug(__FUNCTION__, "Monitor for $var_name disabled", 0);
			return;
		}
		$clean_address = $address;
		if($pos=strpos($address,':')){
			$channel=substr($address,$pos+1);
			$clean_address=substr($address,0,$pos);
		} else $channel=0;
		$isProcessList = is_null($list);
		if($isProcessList)$list= $this->LoadDeviceList();
		
		$item = $this->FindDeviceListItem($address,false,$list);
		$value_changed=false;
		if(empty($item)){
			if(empty($var_value) && $this->ReadPropertyBoolean('LogErrorsOnly')){
				return;
			}
			$list[]=$item=$this->NewDeviceListItem($clean_address,$channel);
			$value_changed=true;
		};
		if(!in_array($channel, $item->channels)){
			$item->channels[]=$channel;
		}
		$old_value = $item->values[$nameIndex] ?? null;
		$item->values[$nameIndex]=$var_value;
		if($old_value!=$var_value){
			$item->count[$nameIndex]=0;
			$item->last[$nameIndex]=time();
			$value_changed=true;
		}
		if(empty($item->count[$nameIndex]))
			$item->count[$nameIndex]=1;
		else $item->count[$nameIndex]++;
		
		if($this->CleanUpDeviceListList($list)){
// 			$list_changed=true;
		}
		if($isProcessList){
			$refreshRate=$this->ReadPropertyInteger('RefreshRate');
			$doFormUpdate = $refreshRate > 0 ? true : $value_changed;
			$this->SaveDeviceList($list,$doFormUpdate);
			
			if($doFormUpdate ){
				$this->LogItem($item);
				if($this->ReadPropertyBoolean('LogErrorToMessageLog')){
					IPS_LogMessage(IPS_GetName($this->InstanceID),sprintf("Addr:%s Name:%s Error:%s %s",
							$address,
							$item->name,
							$this->Translate($var_name.'_NAME'),
							$this->ValueToDeviceListItemValue(substr($var_name,0,2), $var_value))
					);		
				}
			}
			$this->ExecuteEventScript($address,$var_name,$var_value,$item->count[$nameIndex], $item->objIDs );
		}
	}
	// Scripts 
	private function CreateEventScript(string $var_name){
		$scriptID=$this->ReadPropertyInteger($var_name.'_Script');
		if(empty($scriptID) && $scriptID=@$this->GetIDForIdent($var_name.'_SCRIPT')){
			$this->SetBuffer('ExpandConfig',1);
			IPS_SetProperty($this->InstanceID, $var_name.'_Script', $scriptID);
			IPS_ApplyChanges($this->InstanceID);
			return;	
		}
		if(empty($scriptID)){
			$scriptID = IPS_CreateScript(0);
			$name = $this->Translate($var_name.'_NAME').' '.$this->Translate('Monitor') ;
			IPS_SetParent($scriptID,$this->InstanceID);
			IPS_SetName($scriptID,$name);
			IPS_SetIdent($scriptID, $var_name.'_SCRIPT');
			IPS_SetHidden($scriptID, true);
			IPS_SetPosition($scriptID, 99);
			IPS_SetScriptContent($scriptID, '<?php
	$address	= $_IPS["ADDRESS"];  	// homematic adresse die das Ereignis ausgelößt hat
	$value 		= $_IPS["VALUE"];	 	// Wert der gesedet wurde (in der Regel BOOLEAN, bei ERROR der Code 0-7)
	$var_name	= $_IPS["NAME"]; 		// der Variablen/CCU Ident der den Event ausgelößt hat
	$call_count = $_IPS["COUNT"]; 		// die Anzahl der bisherigen gleichen ereignissen
//	$hm_modul_id= $_IPS["OBJECTIDS"]; 	// falls vorhanden array der IPS Object ids der HM Module 
//	$name		= $_IPS["OBJECTNAME"]; 	// HM Module oder CCU Name des Gerätes
	
	switch($address) {
		case "xxxx": 
			// ... code
			break;
		default: IPS_LogMessage("'.$name.'","Address: $address Calls: $call_count Name: $var_name  Value: $value");
	}
  	
?>
');
			$this->SetBuffer('ExpandConfig',1);
			IPS_SetProperty($this->InstanceID, $var_name.'_Script', $scriptID);
			IPS_ApplyChanges($this->InstanceID);
 		}
	}
	private function ExecuteEventScript($address, $var_name, $var_value, $count, $ipsid){
		if(($maxcalls=$this->ReadPropertyInteger('MaxCallsPerEvent')) && $count>$maxcalls){
			$this->SendDebug(__FUNCTION__,"Skip call while Calls ($count) greater MaxCalls ($maxcalls) ",0);
			return;
		}
		$params = ['ADDRESS'=>$address,'NAME'=>$var_name, 'VALUE'=>$var_value,'COUNT'=>$count,'OBJECTNAME'=>$this->Translate($var_name.'_NAME') , 'OBJECTIDS'=>$ipsid];
		if($scriptID = $this->ReadPropertyInteger('GLOBAL_Script')){
			if(IPS_ScriptExists($scriptID)){
				IPS_RunScriptEx($scriptID, $params);
			}
		}
		if($scriptID = $this->ReadPropertyInteger($var_name.'_Script')){
			if(IPS_ScriptExists($scriptID)){
				IPS_RunScriptEx($scriptID, $params);
			}
		}
	}
	// Helper
	private function GetMonitorProps(){
		if(is_null($this->MonitorProps))
			foreach(self::EVENT_FIELDS as $var_name)$this->MonitorProps[$var_name]=$this->ReadPropertyBoolean($var_name.'_Monitor');
		return $this->MonitorProps;
	}
	
	// Internal Variables and Constants
	private $MonitorProps = null;
	private $DeviceList = null;
	private $HMIDs = null;
	
	private const EVENT_FIELDS = ['ERROR','LOWBAT','UNREACH','CONFIG_PENDING','UPDATE_PENDING','MOTION','STATE'];
	private const HM_ERRORS = [0=>'',7=>'Sabotage'];
	private const DATE_FORMAT = 'H:i:s - Y.m.d';
	
}



?>