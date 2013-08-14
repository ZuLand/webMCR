<?php
if (!defined('MCR')) exit;

class ThemeManager extends View {

	private $work_skript;
	private $theme_info_cache;
	
	const tmp_dir = 'tmp/'; // from const dir MCRAFT
	const sign_file = 'sign.txt';

	/** @const */
	public static $true_info = array (
		
		'name',
		'version',
		'author',	
		'about',
		'editable',
	);	
	
    public function ThemeManager($style_sd = false, $work_skript = '?mode=control') { 
		
		/*	Show subdirs used: /admin */
		
		parent::View($style_sd);
		
		$this->theme_info_cache = null;
		$this->work_skript = $work_skript;	
	}

	public static function deleteDir($dirPath) {
	
    if (! is_dir($dirPath)) return;
		
    $files = glob($dirPath . '*', GLOB_MARK); 
	
    foreach ($files as $file) {
	
        if (is_dir($file)) 
		
            self::deleteDir($file);
			
         else 	
		 
			unlink($file);
    }
	
    rmdir($dirPath);
	}
	
	private static function GetThemeDir($theme_id) {
		
		return MCR_STYLE . $theme_id . '/';
	}
	
	private static function AddFolderToZip($dir, $local_cut, $zipArchive){
	
	if (!is_dir($dir)) return false;
	
	$count = 1;
	$lcut_name = str_replace($local_cut, '', $dir, $count);
	
	$fdir = opendir($dir); 

	$zipArchive->addEmptyDir($lcut_name);
	
	//echo 'Create dir ' . $lcut_name . '<br>';

		while (($file = readdir($fdir)) !== false) {
		
			if ($file == ".." || $file == ".") continue;
			
			if (!is_file($dir . $file)) 
			
				self::AddFolderToZip($dir . $file . '/', $local_cut, $zipArchive);
			
			else {
				//echo 'Create file ' . $lcut_name  . $file. '<br>' ;
				$zipArchive->addFile($dir . $file, $lcut_name . $file);
			}
		}		 
	}
	
	public static function TInstall($post_name) {
	
		if (!POSTGood($post_name, array('zip'))) return 1;
		
		$new_file_info = POSTSafeMove($post_name, $this->base_dir); 
		if (!$new_file_info) return 2;
		
		$way  = $this->base_dir.$new_file_info['tmp_name'];		
		if ($zip->open($way) === false)  return 3;
		
		$theme_info = $zip->getFromName(self::sign_file);
		if ($theme_info === false) return 4;
		
		$theme_info = self::GetThemeInfo(false, $theme_info);
		if (empty($theme_info['name'])) return 5;
		
		if (!preg_match("/^[a-zA-Z0-9_-]+$/", $theme_info['name'])) return 6;
		
		$theme_info['id'] = str_replace(' ', '', $theme_info['name']);
		$theme_dir = self::GetThemeDir($theme_info['id']);
		
		if (!is_dir($theme_dir)) {
			
			if (mkdir($theme_dir, 0766, true) === false) return 7;
			
		} else {
		
			self::deleteDir($theme_dir);
		}
		
		if ($zip->extractTo($theme_dir) === false ) return 8;	

		return 0;
	}
	
	public static function DownloadTInstaller($theme_id) {
	
		$theme_info = self::GetThemeInfo($theme_id);
		if ($theme_info === false ) return false;
		
		self::SaveThemeInfo($theme_id, $theme_info);
		
		$tmp_base_dir = MCRAFT.self::tmp_dir;
		$tmp_fname = tmp_name($tmp_base_dir);
		$tmp_file = $tmp_base_dir.$tmp_fname;
		
		$zip = new ZipArchive;
		if ($zip->open($tmp_file, ZipArchive::CREATE) === false) return false;
		
		self::addFolderToZip(self::GetThemeDir($theme_id), self::GetThemeDir($theme_id), $zip);
		
		$zip->close();
		
		$fsize = filesize($tmp_file);
		
		if (round($fsize / 1048576) > 50) { unlink($tmp_file);	return false; }
		
		$out_name = urlencode('mcr_'.$theme_id.'.zip');		
		
		header('Content-Type:application/zip;name='.$out_name); 
		header('Content-Transfer-Encoding:binary'); 
		header('Content-Length:'.$fsize); 
		header('Content-Disposition:attachment;filename='.$out_name); 
		header('Expires:0'); 
		header('Cache-Control:no-cache, must-revalidate'); 
		header('Pragma:no-cache'); 	
		
		readfile($tmp_file);
		unlink($tmp_file);
	}
	
	public function isThemesEnabled() {
	global $config;
	
		if ($this->theme_info_cache === 'depricated') return false;
		
		if (!isset($config['s_theme']) or file_exists(MCR_STYLE.'index.html') ) {
		
		$this->theme_info_cache = 'depricated';
		return false;
		}
		
		return true;		
	}
	
	public function GetThemeSelectorOpt() {
	global $config;
	
		if (!$this->isThemesEnabled()) return '<option value="-1">'.lng('NOT_SET').'</option>';

		$theme_list = $this->GetThemeList();
			
		$html_list = '';

		for($i=0; $i < sizeof($theme_list); $i++) 
			
			$html_list .= '<option value="'.$theme_list[$i]['id'].'" '.(($theme_list[$i]['id'] === $config['s_theme'])? 'selected' : '' ).'>'.$theme_list[$i]['id'].'</option>';
				
		return $html_list;
	}
	
	public function ShowThemeSelector() {
	global $config;
	
	if (!$this->isThemesEnabled()) return '';
	
	$theme_cur = isset($config['s_theme'])? $config['s_theme'] : View::def_theme;
	
	$theme_list = $this->GetThemeList();
	
	$html_theme_list = $this->GetThemeSelectorOpt();		
		
	ob_start(); 
	
		foreach ($theme_list as $key => $theme_info)
			
			include $this->GetView('admin/theme/theme_item.html'); 

    $theme_items_info = ob_get_clean();	
	
	ob_start(); 
	
		include $this->GetView('admin/theme/theme_select.html');
	
	return ob_get_clean();	
	}	
	
	public function GetThemeList() {

	if ($this->theme_info_cache != null ) return $this->theme_info_cache;
	
	if (!$this->isThemesEnabled()) return $this->theme_info_cache; 
	
	$this->theme_info_cache = array();		
	
	if ($theme_dir = opendir(MCR_STYLE)) { 

       while (false !== ($theme = readdir($theme_dir))) {
	   
			if ($theme == '.' or  $theme == '..' or !file_exists(MCR_STYLE. $theme . '/' . self::sign_file)) 
			
				continue;
				
            else {
			
				$theme_info = self::GetThemeInfo($theme); 
				if ($theme_info === false) continue;
				
				$this->theme_info_cache[] = $theme_info;
			}
				
		}

       closedir($theme_dir);  
	}
	
	return $this->theme_info_cache;
	}
	
	public static function SaveThemeInfo($theme_id, $theme_info, $editable = false) {
		
		if (empty($theme_info['name'])) return false;
		
		$fp = fopen(self::GetThemeDir($theme_id) . self::sign_file, "w");
		if ($fp === false) return false;
		
		flock($fp,LOCK_EX);
		
		foreach ($theme_info as $key => $value ) {
		
			if ($key == 'id' or $key == 'editable') continue;
			fwrite($fp, $key .'='. $value . "; \r\n");
		}
			
		fwrite($fp, 'editable='. (($editable)? 'yes' : 'no' ) . "; \r\n");
		
		flock($fp,LOCK_UN);
		fclose($fp);
		
		return true;
	}
	
	public static function GetThemeInfo($theme_id, $theme_info_txt = false) {
		
		$theme_info = array();
		$theme_info['id'] = false;
		
		if (!$theme_id and !$theme_info_txt) return false;		
		
		if ($theme_id) {
		
			$theme_info['id'] = $theme_id;		
			$sign_file = self::GetThemeDir($theme_id). self::sign_file;
			
				if (!file_exists($sign_file)) return false;
			
				if (filesize($sign_file) > 128 * 1024) return false;
			
			$theme_info_txt =  file_get_contents($sign_file);
		}
		
		$theme_info_txt =  explode (';', $theme_info_txt);
			
			if (!sizeof($theme_info_txt)) return false;
			
			for($i=0; $i < sizeof($theme_info_txt); $i++) {
			
				for ($b=0; $b < sizeof(self::$true_info); $b++) {
				
					if ( !substr_count($theme_info_txt[$i], self::$true_info[$b])) 
						
						continue; 
						
						$info_value =  explode ('=', $theme_info_txt[$i]);
						
						if (sizeof($info_value) == 2)
						
							$theme_info[self::$true_info[$b]] = trim( preg_replace('/\s{2,}/', ' ', nl2br($info_value[1]) ) );					
				}
			}
		
		return $theme_info;	
	}
}

class ControlManager extends Manager {
private $work_skript;

    public function ControlManager($style_sd = false, $work_skript = '?mode=control') { 
		
		/*	Show subdirs used: /admin */
		
		parent::Manager($style_sd);
		
		$this->work_skript = $work_skript;	
	}

	public function ShowUserListing($list = 1, $search_by = 'name', $input = false) {
	global $bd_users,$bd_names;

		$input = TextBase::SQLSafe($input);
	
	    if ($input == 'banned') $input = 0;
	
	    if ($search_by == 'name') $result = BD("SELECT `{$bd_users['id']}` FROM `{$bd_names['users']}` WHERE {$bd_users['login']} LIKE '%$input%' ORDER BY {$bd_users['login']} LIMIT ".(10*($list-1)).",10"); 
    elseif ($search_by == 'none') $result = BD("SELECT `{$bd_users['id']}` FROM `{$bd_names['users']}` ORDER BY {$bd_users['login']} LIMIT ".(10*($list-1)).",10"); 
	elseif ($search_by == 'ip'  ) $result = BD("SELECT `{$bd_users['id']}` FROM `{$bd_names['users']}` WHERE {$bd_users['ip']} LIKE '%$input%' ORDER BY {$bd_users['login']} LIMIT ".(10*($list-1)).",10"); 
	elseif ($search_by == 'lvl' ) {
	
		$result = BD("SELECT `id` FROM `{$bd_names['groups']}` WHERE `lvl`='$input'");
		
		$id_group  = mysql_fetch_array( $result, MYSQL_NUM );    
	    $input = $id_group[0];
		
	    $result = BD("SELECT `{$bd_users['id']}` FROM `{$bd_names['users']}` WHERE `{$bd_users['group']}` = '$input' ORDER BY {$bd_users['login']} LIMIT ".(10*($list-1)).",10"); 
	}
        
		ob_start(); 		

	          $resnum =  mysql_num_rows( $result );	
	    if ( !$resnum ) { include $this->GetView('admin/user/user_not_found.html'); return ob_get_clean(); }  
		
        include $this->GetView('admin/user/user_find_header.html'); 
  
		while ( $line = mysql_fetch_array( $result, MYSQL_NUM ) ) {
		
            $inf_user = new User($line[0],$bd_users['id']);
			
            $user_name = $inf_user->name();
            $user_id   = $inf_user->id();
            $user_ip   = $inf_user->ip();
            $user_lvl  = $inf_user->getGroupName();
			$user_lvl_id = $inf_user->group();
			
            unset($inf_user);
			
            include $this->GetView('admin/user/user_find_string.html'); 
        } 
		
		include $this->GetView('admin/user/user_find_footer.html'); 

        $html = ob_get_clean();

	    if ($search_by == 'name') $result = BD("SELECT COUNT(*) FROM `{$bd_names['users']}` WHERE {$bd_users['login']} LIKE '%$input%'");
	elseif ($search_by == 'none') $result = BD("SELECT COUNT(*) FROM `{$bd_names['users']}`");
	elseif ($search_by == 'ip'  ) $result = BD("SELECT COUNT(*) FROM `{$bd_names['users']}` WHERE {$bd_users['ip']} LIKE '%$input%'");
	elseif ($search_by == 'lvl' ) $result = BD("SELECT COUNT(*) FROM `{$bd_names['users']}` WHERE `{$bd_users['group']}`='$input'");
		
		$line = mysql_fetch_array($result);
		$html .= $this->arrowsGenerator($this->work_skript, $list, $line[0], 10);
      
     return $html;
	}
	
    public function ShowServers($list) { 
    global $bd_names;

    ob_start(); 	
	
    include $this->GetView('admin/server/servers_caption.html');
	
	// TODO increase priority by votes
	
    $result = BD("SELECT * FROM `{$bd_names['servers']}` ORDER BY priority DESC LIMIT ".(10*($list-1)).",10");  
    $resnum = mysql_num_rows( $result );
	
	if ( !$resnum ) { include $this->GetView('admin/server/servers_not_found.html'); return ob_get_clean(); }  
		
	include $this->GetView('admin/server/servers_header.html'); 
		
		while ( $line = mysql_fetch_array( $result ) ) {
		
            $server_name     = $line['name'];
			$server_address  = $line['address'];
			$server_info     = $line['info'];
			$server_port     = $line['port'];
	        $server_method   = '';
			
			switch ((int)$line['method']) {
			case 0: $server_method = 'Simple query'; break;
			case 1: $server_method = 'Query'; break; 
			case 2: $server_method = 'RCON'; break;
                        case 3: $server_method = 'JSONAPI'; break;
			}			
			$server_id       = $line['id'];
		
		include $this->GetView('admin/server/servers_string.html');         
        }
        
	include $this->GetView('admin/server/servers_footer.html'); 
	$html = ob_get_clean();
	
		$result = BD("SELECT COUNT(*) FROM `{$bd_names['servers']}`");
		$line = mysql_fetch_array($result); 
		$resnum = $line[0];
					  		  
		$html .= $this->arrowsGenerator($this->work_skript, $list, $line[0], 10);

    return $html;
    }
	
    public function ShowIpBans($list) {
    global $bd_names;

    RefreshBans();

    ob_start(); 	
	
    include $this->GetView('admin/ban/ban_ip_caption.html');
	
    $result = BD("SELECT * FROM `{$bd_names['ip_banning']}` ORDER BY ban_until DESC LIMIT ".(10*($list-1)).",10");  
    $resnum = mysql_num_rows( $result );
	
	if ( !$resnum ) { include $this->GetView('admin/ban/ban_ip_not_found.html'); return ob_get_clean(); }  
		
	include $this->GetView('admin/ban/ban_ip_header.html'); 
		
		while ( $line = mysql_fetch_array( $result ) ) {
		
             $ban_ip    = $line['IP'];
             $ban_start = $line['time_start'];
             $ban_end   = $line['ban_until'];
			 $ban_type  = $line['ban_type'];
			 $ban_reason  = $line['reason'];			 
			 
		     include $this->GetView('admin/ban/ban_ip_string.html'); 
        
        }
        
	include $this->GetView('admin/ban/ban_ip_footer.html'); 
	$html = ob_get_clean();
	
		$result = BD("SELECT COUNT(*) FROM `{$bd_names['ip_banning']}`");
		$line = mysql_fetch_array($result); 
		$resnum = $line[0];
					  		  
		$html .= $this->arrowsGenerator($this->work_skript, $list, $line[0], 10);

    return $html;
    }
}
?>