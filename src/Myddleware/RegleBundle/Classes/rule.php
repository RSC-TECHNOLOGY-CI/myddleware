<?php
/*********************************************************************************
 * This file is part of Myddleware.

 * @package Myddleware
 * @copyright Copyright (C) 2013 - 2015  Stéphane Faure - CRMconsult EURL
 * @copyright Copyright (C) 2015 - 2016  Stéphane Faure - Myddleware ltd - contact@myddleware.com
 * @link http://www.myddleware.com	
 
 This file is part of Myddleware.
 
 Myddleware is free software: you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation, either version 3 of the License, or
 (at your option) any later version.

 Myddleware is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with Myddleware.  If not, see <http://www.gnu.org/licenses/>.
*********************************************************************************/

namespace Myddleware\RegleBundle\Classes;

use Symfony\Bridge\Monolog\Logger; // Logs
use Symfony\Component\DependencyInjection\ContainerInterface as Container; // Service access
use Doctrine\DBAL\Connection; // Connection database

use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Filesystem\Filesystem;

use Myddleware\RegleBundle\Classes\tools as MyddlewareTools; // Tools

class rulecore {
	
	protected $connection;
	protected $container;
	protected $logger;
	protected $em;
	protected $ruleId;
	protected $rule;
	protected $ruleFields;
	protected $ruleParams;
	protected $sourceFields;
	protected $targetFields;
	protected $fieldsType;
	protected $ruleRelationships;
	protected $ruleFilters;
	protected $solutionSource;
	protected $solutionTarget;
	protected $jobId;
	protected $manual;
	protected $key;
	protected $limit = 100;
	protected $tools;
	
    public function __construct(Logger $logger, Container $container, Connection $dbalConnection, $param) {
    	$this->logger = $logger;
		$this->container = $container;
		$this->connection = $dbalConnection;
		$this->em = $this->container->get('doctrine')->getEntityManager();
		
		if (!empty($param['ruleId'])) {
			$this->ruleId = $param['ruleId'];
			$this->setRule($this->ruleId);
		}		
		if (!empty($param['jobId'])) {
			$this->jobId = $param['jobId'];
		}	
		if (!empty($param['manual'])) {
			$this->manual = $param['manual'];
		}	
		if (!empty($param['limit'])) {
			$this->limit = $param['limit'];
		}
		$this->setRuleParams();
		$this->setRuleRelationships();
		$this->setRuleFields();
		$this->tools = new MyddlewareTools($this->logger, $this->container, $this->connection);	
	}
	
	public function setRule($idRule) {
		$this->ruleId = $idRule;
		if (!empty($this->ruleId)) {
			$rule = "SELECT *, (SELECT rulep_value FROM RuleParams WHERE rule_id = :ruleId and rulep_name= 'mode') rule_mode FROM Rule WHERE rule_id = :ruleId";
		    $stmt = $this->connection->prepare($rule);
			$stmt->bindValue(":ruleId", $this->ruleId);
		    $stmt->execute();
			$this->rule = $stmt->fetch();
		}
	}
	
	// Generate a document for the current rule for a specific id in the source application. We don't use the reference for the function read.
	// If parameter readSource is false, it means that the data source are already in the parameter param, so no need to read in the source application 
	public function generateDocument($idSource, $readSource = true, $param = '', $idFiledName = 'id') {
		try {
			if ($readSource) {
				// Connection to source application
				$connexionSolution = $this->connexionSolution('source');
				if ($connexionSolution === false) {
					throw new \Exception ('Failed to connect to the source solution.');
				}
				
				// Read data in the source application
				$read['module'] = $this->rule['rule_module_source'];
				$read['fields'] = $this->sourceFields;
				$read['ruleParams'] = $this->ruleParams;
				$read['rule'] = $this->rule;
				$read['query'] = array($idFiledName => $idSource);
				$dataSource = $this->solutionSource->read_last($read);
				if (!$dataSource['done']) {
					throw new \Exception ('Failed to read record '.$idSource.' in the module '.$read['module'].' of the source solution. '.(!empty($dataSource['error']) ? $dataSource['error'] : ''));
				}
			}
			else {
				$dataSource['values'] = $param['values'];
			}
			
			// Generate document
			$doc['rule'] = $this->rule;
			$doc['ruleFields'] = $this->ruleFields;
			$doc['ruleRelationships'] = $this->ruleRelationships;
			$doc['data'] = $dataSource['values'];
			$doc['jobId'] = $this->jobId;			
			$document = new document($this->logger, $this->container, $this->connection, $doc);
			$createDocument = $document->createDocument();		
			if (!$createDocument) {
				throw new \Exception ('Failed to create document : '.$document->getMessage());
			}
			return $document;
		} catch (\Exception $e) {
			$error = 'Error : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )';
			$this->logger->error($error);
			$errorObj = new \stdClass();
			$errorObj->error = $error;		
			return $errorObj;
		}	
	}
	
	// Connect to the source or target application
	public function connexionSolution($type) {

		try {
			if ($type == 'source') {
				$connId = $this->rule['conn_id_source'];
			}
			elseif ($type == 'target') {
				$connId = $this->rule['conn_id_target'];
			}
			else {
				return false;
			}
			
			// Get the name of the application			
		    $sql = "SELECT sol_name  
		    		FROM Connector
		    		JOIN Solution USING( sol_id )
		    		WHERE conn_id = :connId";
		    $stmt = $this->connection->prepare($sql);
			$stmt->bindValue(":connId", $connId);
		    $stmt->execute();		
			$r = $stmt->fetch();	
			
			// Get params connection
		    $sql = "SELECT conp_id, conn_id, conp_name, conp_value
		    		FROM ConnectorParams 
		    		WHERE conn_id = :connId";
		    $stmt = $this->connection->prepare($sql);
			$stmt->bindValue(":connId", $connId);
		    $stmt->execute();	    
			$tab_params = $stmt->fetchAll();
	
			$params = array();
			if(!empty($tab_params)) {
				foreach ($tab_params as $key => $value) {
					$params[$value['conp_name']] = $value['conp_value'];
					$params['ids'][$value['conp_name']] = array('conp_id' => $value['conp_id'],'conn_id' => $value['conn_id']);
				}			
			}
			
			// Connect to the application
			if ($type == 'source') {	
				$this->solutionSource = $this->container->get('myddleware_rule.'.$r['sol_name']);				
				$loginResult = $this->solutionSource->login($params);			
				$c = (($this->solutionSource->connexion_valide) ? true : false );				
			}
			else {
				$this->solutionTarget = $this->container->get('myddleware_rule.'.$r['sol_name']);		
				$loginResult = $this->solutionTarget->login($params);			
				$c = (($this->solutionTarget->connexion_valide) ? true : false );			
			}
			if(!empty($loginResult['error'])) {
				return $loginResult;
			}

			return $c; 			
		} catch (\Exception $e) {
			$this->logger->error( 'Error : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )' );
			return false;
		}	
	}

	// Logout to the application
	protected function logoutSolution($type) {
		try {	
			if ($type == 'source') {
				$this->solutionSource = $this->container->get('myddleware_rule.'.$r['sol_name']);		
				return $this->solutionSource->logout($params);							
			}
			else {
				$this->solutionTarget = $this->container->get('myddleware_rule.'.$r['sol_name']);		
				return $this->solutionTarget->logout($params);				
			}
		} catch (\Exception $e) {
			$this->logger->error( 'Error : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )' );
			return false;
		}	
	}
	
	// Permet de mettre toutes les données lues dans le système source dans le tableau $this->dataSource
	// Cette fonction retourne le nombre d'enregistrements lus
	public function createDocuments() {	
		$readSource = null;
		// Si la lecture pour la règle n'est pas désactivée
		// Et si la règle est active et pas supprimée ou bien le lancement est en manuel
		if (
				empty($this->ruleParams['disableRead'])
			&&	(
					(
							$this->rule['rule_deleted'] == 0
						&& $this->rule['rule_active'] == 1	
					)
					|| (
						$this->manual == 1
					)
				)
		) {
			// lecture des données dans la source
			$readSource = $this->readSource();
			if (empty($readSource['error'])) {
				$readSource['error'] = '';
			}
			// Si erreur
			if (!isset($readSource['count'])) {
				return $readSource;
			}		
			$this->connection->beginTransaction(); // -- BEGIN TRANSACTION suspend auto-commit
			try {
				if ($readSource['count'] > 0) {					
					include_once 'document.php';		
					$param['rule'] = $this->rule;
					$param['ruleFields'] = $this->ruleFields;
					$param['ruleRelationships'] = $this->ruleRelationships;
					$i = 0;
					if($this->dataSource['values']) {

						// Boucle sur chaque document
						foreach ($this->dataSource['values'] as $row) {
							if ($i >= 1000){
								$this->connection->commit(); // -- COMMIT TRANSACTION
								$this->connection->beginTransaction(); // -- BEGIN TRANSACTION suspend auto-commit
								$i = 0;
							}
							$i++;
							$param['data'] = $row;
							$param['jobId'] = $this->jobId;
							$param['fieldsType'] = $this->fieldsType;
							$document = new document($this->logger, $this->container, $this->connection, $param);
							$createDocument = $document->createDocument();
							if (!$createDocument) {
								$readSource['error'] .= $document->getMessage();
							}
						}			
					}
					// Mise à jour de la date de référence si des documents ont été créés
					$this->updateReferenceDate();
				}
				// Rollback if the job has been manually stopped
				if ($this->getJobStatus() != 'Start') {
					throw new \Exception('The task has been stopped manually during the document creation. No document generated. ');
				}
				$this->connection->commit(); // -- COMMIT TRANSACTION
			} catch (\Exception $e) {
				$this->connection->rollBack(); // -- ROLLBACK TRANSACTION
				$this->logger->error( 'Failed to create documents : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )' );
				$readSource['error'] = 'Failed to create documents : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )';
			}	
		}
		// On affiche pas d'erreur si la lecture est désactivée
		elseif (empty($this->ruleParams['disableRead'])) {
			$readSource['error'] = 'The rule '.$this->rule['rule_name_slug'].' version '.$this->rule['rule_version'].($this->rule['rule_deleted'] == 1 ? ' is deleted.' : ' is disabled.');
		}
		return $readSource;
	}
	
	protected function getJobStatus() {
		$sqlJobDetail = "SELECT * FROM Job WHERE job_id = :jobId";
		$stmt = $this->connection->prepare($sqlJobDetail);
		$stmt->bindValue(":jobId", $this->jobId);
		$stmt->execute();	    
		$job = $stmt->fetch(); // 1 row
		if (!empty($job['job_status'])) {
			return $job['job_status'];
		}
		return false;
	}
	
	// Permet de mettre à jour la date de référence pour ne pas récupérer une nouvelle fois les données qui viennent d'être écrites dans la cible
	protected function updateReferenceDate() {			
		$date_ref = $this->dataSource['date_ref'];
		$sqlDateReference = "UPDATE RuleParams SET rulep_value = :date_ref WHERE rulep_name = 'datereference' AND rule_id = :ruleId";
		$stmt = $this->connection->prepare($sqlDateReference);
		$stmt->bindValue(":ruleId", $this->ruleId);
		$stmt->bindValue(":date_ref", $date_ref);
		$stmt->execute();			
	}
	
	protected function readSource() {
		
		$read['module'] = $this->rule['rule_module_source'];
		$read['rule'] = $this->rule;
		$read['date_ref'] = $this->ruleParams['datereference'];
		$read['ruleParams'] = $this->ruleParams;
		$read['fields'] = $this->sourceFields;
		$read['offset'] = 0;
		$read['limit'] = $this->limit;
		$read['jobId'] = $this->jobId;
		$read['manual'] = $this->manual;
		// Ajout des champs source des relations de la règle
		if (!empty($this->ruleRelationships)) {
			foreach ($this->ruleRelationships as $ruleRelationship) {
				$read['fields'][] = $ruleRelationship['rrs_field_name_source'];
			}
		}

		// si champs vide
		if(!empty($read['fields'])) {
			$connect = $this->connexionSolution('source');
			if ($connect === true) {
				$this->dataSource = $this->solutionSource->read($read);
				// Si on a $this->limit résultats et que la date de référence n'a pas changée alors on récupère les enregistrements suivants
				// Récupération de la date de modification du premier enregistrement
				$value['date_modified'] = '';
				if (!empty($this->dataSource['values'])) {
					$value = current($this->dataSource['values']);
				}
				if (
						!empty($this->dataSource['count'])
					&&	$this->dataSource['count'] == $this->limit
					&& $this->dataSource['date_ref'] == $value['date_modified']
				) {
					$i = 0;
					$dataSource = $this->dataSource;
					// On boucle tant que l'on a pas de modification de date... Il faut prendre tous les enregistrement s'il y en a plus de $this->limit qui ont été créés à la même heure.
					while (
							$dataSource['count'] == $this->limit
						&& $dataSource['date_ref'] == $value['date_modified']
					) {
						// Gestion de l'offset
						$i++;
						$read['offset'] = $i*$this->limit;
						// On récupère les enregistrements suivants
						$dataSource = $this->solutionSource->read($read);
						if(empty($dataSource) || $dataSource['count'] == 0) break;
						// Sauvegarde des élément dans le tableau final
						$this->dataSource['values'] = array_merge($this->dataSource['values'],$dataSource['values']);
						$this->dataSource['count'] += $dataSource['count'];
						$this->dataSource['date_ref'] = $dataSource['date_ref'];
					}
				}
				// Logout (source solution)
				if (!empty($this->solutionSource)) {
					$loginResult = $this->solutionSource->logout();	
					if (!$loginResult) {
						$this->dataSource['error'] .= 'Failed to logout from the source solution';
					}
				}
				return $this->dataSource;		
			}
			elseif (!empty($connect['error'])){
				return $connect;
			}
			else {
				return array('error' => 'Failed to connect to the source with rule : '.$this->ruleId.' .' );
			}
		}
		return array('error' => 'No field to read in source system. ');
	} 
	
	// Permet de filtrer les nouveau documents d'une règle
	public function filterDocuments($documents = null) {
		include_once 'document.php';
		$response = array();
		
		// Sélection de tous les docuements de la règle au statut 'New' si aucun document n'est en paramètre
		if (empty($documents)) {
			$documents = $this->selectDocuments('New');
		}

		// Pour tous les docuements sélectionnés on vérifie les prédécesseurs
		if(!empty($documents)) {
			$this->setRuleFilters();
			foreach ($documents as $document) { 
				$param['id_doc_myddleware'] = $document['id'];
				$param['jobId'] = $this->jobId;
				$doc = new document($this->logger, $this->container, $this->connection, $param);
				$response[$document['id']] = $doc->filterDocument($this->ruleFilters);
			}			
		}
		return $response;
	}
    
	// Permet de contrôler si un document de la même règle pour le même enregistrement n'est pas close
	// Si un document n'est pas clos alors le statut du docuement est mis à "pending"
	public function ckeckPredecessorDocuments($documents = null) {
		include_once 'document.php';
		$response = array();
			
		// Sélection de tous les docuements de la règle au statut 'Filter_OK' si aucun document n'est en paramètre
		if (empty($documents)) {
			$documents = $this->selectDocuments('Filter_OK');
		}
		// Pour tous les docuements sélectionnés on vérifie les prédécesseurs
		if(!empty($documents)) { 
			foreach ($documents as $document) { 
				$param['id_doc_myddleware'] = $document['id'];
				$param['jobId'] = $this->jobId;
				$doc = new document($this->logger, $this->container, $this->connection, $param);
				$response[$document['id']] = $doc->ckeckPredecessorDocument();
			}			
		}
		return $response;
	}
    
	// Permet de contrôler si un document de la même règle pour le même enregistrement n'est pas close
	// Si un document n'est pas clos alors le statut du docuement est mis à "pending"
	public function ckeckParentDocuments($documents = null) {
		include_once 'document.php';
		// Permet de charger dans la classe toutes les relations de la règle
		$response = array();
		
		// Sélection de tous les docuements de la règle au statut 'New' si aucun document n'est en paramètre
		if (empty($documents)) {
			$documents = $this->selectDocuments('Predecessor_OK');
		}
		if(!empty($documents)) {
			// Pour tous les docuements sélectionnés on vérifie les parents
			foreach ($documents as $document) { 
				$param['id_doc_myddleware'] = $document['id'];
				$param['jobId'] = $this->jobId;
				$doc = new document($this->logger, $this->container, $this->connection, $param);
				$response[$document['id']] = $doc->ckeckParentDocument($this->ruleRelationships);
			}			
		}			
		return $response;
	}
    
	// Permet de contrôler si un docuement de la même règle pour le même enregistrement n'est pas close
	// Si un document n'est pas clos alors le statut du docuement est mis à "pending"
	public function transformDocuments($documents = null){
		include_once 'document.php';
		// Permet de charger dans la classe toutes les relations de la règle
		$response = array();
	
		// Sélection de tous les docuements de la règle au statut 'New' si aucun document n'est en paramètre
		if (empty($documents)) {
			$documents = $this->selectDocuments('Relate_OK');
		}
		if(!empty($documents)) {
			// Transformation de tous les docuements sélectionnés
			foreach ($documents as $document) { 
				$param['id_doc_myddleware'] = $document['id'];
				$param['ruleFields'] = $this->ruleFields;
				$param['ruleRelationships'] = $this->ruleRelationships;
				$param['jobId'] = $this->jobId;
				$param['key'] = $this->key;
				$doc = new document($this->logger, $this->container, $this->connection, $param);
				$response[$document['id']] = $doc->transformDocument();
			}			
		}	
		return $response;		
	}
	

	// Permet de récupérer les données de la cible avant modification des données
	// 2 cas de figure : 
	//     - Le document est un document de modification
	//     - Le document est un document de création mais la règle a un paramètre de vérification des données pour ne pas créer de doublon
	public function getTargetDataDocuments($documents = null) {
		include_once 'document.php';
		// Permet de charger dans la classe toutes les relations de la règle
		$response = array();
		
		// Sélection de tous les docuements de la règle au statut 'New' si aucun document n'est en paramètre
		if (empty($documents)) {
			$documents = $this->selectDocuments('Transformed');
		}
		
		if(!empty($documents)) {
			// Connexion à la solution cible pour rechercher les données
			$this->connexionSolution('target');
			
			// Récupération de toutes les données dans la cible pour chaque document
			foreach ($documents as $document) {
				$param['id_doc_myddleware'] = $document['id'];
				$param['solutionTarget'] = $this->solutionTarget;
				$param['ruleFields'] = $this->ruleFields;
				$param['jobId'] = $this->jobId;
				$param['key'] = $this->key;
				$doc = new document($this->logger, $this->container, $this->connection, $param);
				$response[$document['id']] = $doc->getTargetDataDocument();
				$response['doc_status'] = $doc->getStatus();
			}			
		}	
		return $response;			
	}
	
	public function sendDocuments() {	
		// Création des données dans la cible
		$sendTarget = $this->sendTarget('C');
		// Modification des données dans la cible
		$sendTarget = $this->sendTarget('U');
		// Logout target solution
		if (!empty($this->solutionTarget)) {
			$loginResult['error'] = $this->solutionTarget->logout();	
			if (!$loginResult) {
				$sendTarget .= 'Failed to logout from the target solution';
			}
		}
		return $sendTarget;
	}
	
	public function actionDocument($id_document,$event) {
		switch ($event) { 
			case 'rerun':
				return $this->rerun($id_document);
				break;
			case 'cancel':
				return $this->cancel($id_document);
				break;
			default:
				return 'Action '.$event.' unknown. Failed to run this action. ';
		}
	}
	
	public function actionRule($event) {
		switch ($event) {
			case 'ALL':
				return $this->runMyddlewareJob("ALL");
				break;
			case 'ERROR':
				return $this->runMyddlewareJob("ERROR");
				break;
			case 'runMyddlewareJob':
				return $this->runMyddlewareJob($this->rule['rule_name_slug']);
				break;
			default:
				return 'Action '.$event.' unknown. Failed to run this action. ';
		}
	}
	
	// Permet de faire des contrôles dans Myddleware avant sauvegarde de la règle
	// Si le retour est false, alors la sauvegarde n'est pas effectuée et un message d'erreur est indiqué à l'utilisateur
	// data est de la forme : 
		// [ruleName] => nom
		// [ruleVersion] => 001
		// [oldRule] => id de la règle précédente
		// [connector] => Array ( [source] => 3 [cible] => 30 ) 
		// [content] => Array ( 
			// [fields] => Array ( [name] => Array ( [Date] => Array ( [champs] => Array ( [0] => date_entered [1] => date_modified ) [formule] => Array ( [0] => {date_entered}.{date_modified} ) ) [account_Filter] => Array ( [champs] => Array ( [0] => name ) ) ) ) 
			// [params] => Array ( [mode] => 0 ) ) 
		// [relationships] => Array ( [0] => Array ( [target] => compte_Reference [rule] => 54ea64f1601fc [source] => Myddleware_element_id ) ) 
		// [module] => Array ( [source] => Array ( [solution] => sugarcrm [name] => Accounts ) [target] => Array ( [solution] => bittle [name] => oppt_multi7 ) ) 
	// La valeur de retour est de a forme : array('done'=>false, 'message'=>'message erreur');	ou array('done'=>true, 'message'=>'')
	public static function beforeSave($containeur,$data) {
		// Contrôle sur la solution source
		$solutionSource = $containeur->get('myddleware_rule.'.$data['module']['source']['solution']);
		$check = $solutionSource->beforeRuleSave($data,'source');
		// Si OK contôle sur la solution cible
		if ($check['done']) {
			$solutionTarget = $containeur->get('myddleware_rule.'.$data['module']['target']['solution']);
			$check = $solutionTarget->beforeRuleSave($data,'target');
		}
		return $check;
	}
	
	// Permet d'effectuer une action après la sauvegarde de la règle dans Myddleqare
	// Mêmes paramètres en entrée que pour la fonction beforeSave sauf que l'on a ajouté les entrées ruleId et date de référence au tableau
	public static function afterSave($containeur,$data) {
		// Contrôle sur la solution source
		$solutionSource = $containeur->get('myddleware_rule.'.$data['module']['source']['solution']);
		$messagesSource = $solutionSource->afterRuleSave($data,'source');

		$solutionTarget = $containeur->get('myddleware_rule.'.$data['module']['target']['solution']);
		$messagesTarget = $solutionTarget->afterRuleSave($data,'target');
		
		$messages = array_merge($messagesSource,$messagesTarget);
		$data['testMessage'] = '';
		// Affichage des messages
		if (!empty($messages)) {
			$session = new Session();
			foreach ($messages as $message) {
				if ($message['type'] == 'error') {
					$errorMessages[] = $message['message'];
				}
				else {
					$successMessages[] = $message['message'];
				}
				$data['testMessage'] .= $message['type'].' : '.$message['message'].chr(10);
			}
			if (!empty($errorMessages)) {
				$session->set( 'error', $errorMessages);
			}
			if (!empty($successMessages)) {
				$session->set( 'success', $successMessages);
			}
		}
	}
	
	// Permet de récupérer les règles potentiellement biderectionnelle.
	// Cette fonction renvoie les règles qui utilisent les même connecteurs et modules que la règle en cours mais en sens inverse (source et target inversées)
	// On est sur une méthode statique c'est pour cela que l'on récupère la connexion e paramètre et non dans les attributs de la règle							
	public static function getBidirectionalRules($connection, $params) {
		try {					
			// Récupération des règles opposées à la règle en cours de création
			$queryBidirectionalRules = "SELECT 
											rule_id, 
											rule_name
										FROM Rule 
										WHERE 
												conn_id_source = :conn_id_target
											AND conn_id_target = :conn_id_source
											AND rule_module_source = :rule_module_target
											AND rule_module_target = :rule_module_source
											AND rule_deleted = 0
										";
			$stmt = $connection->prepare($queryBidirectionalRules);
			$stmt->bindValue(":conn_id_source", $params['connector']['source']);
			$stmt->bindValue(":conn_id_target", $params['connector']['cible']);
			$stmt->bindValue(":rule_module_source", $params['module']['source']);
			$stmt->bindValue(":rule_module_target", $params['module']['cible']);
		    $stmt->execute();	   				
			$bidirectionalRules = $stmt->fetchAll();
			
			// Construction du tableau de sortie
			if (!empty($bidirectionalRules)) {
				$option[''] = ''; 
				foreach ($bidirectionalRules as $rule) {
					$option[$rule['rule_id']] = $rule['rule_name'];
				}
				if (!empty($option)) {
					return array(	
						array(
							'id' 		=> 'bidirectional',
							'name' 		=> 'bidirectional',
							'required'	=> false,
							'type'		=> 'option',
							'label' => 'create_rule.step3.params.sync',
							'option'	=> $option
						)
					);		
				}
			}
		} catch (\Exception $e) {
			return null;
		}
		return null;
	}
		
	// Permet d'annuler un docuement 
	protected function cancel($id_document) {
		$param['id_doc_myddleware'] = $id_document;
		$param['jobId'] = $this->jobId;
		$param['key'] = $this->key;
		$doc = new document($this->logger, $this->container, $this->connection, $param);
		$doc->updateStatus('Cancel'); 
		$session = new Session();
		$message = $doc->getMessage();
		
		// Si on a pas de jobId cela signifie que l'opération n'est pas massive mais sur un seul document
		// On affiche alors le message directement dans Myddleware
		if (empty($this->jobId)) {
			if (empty($message)) {
				$session->set( 'success', array('Annulation du transfert effectuée avec succès.'));
			}
			else {
				$session->set( 'error', array($doc->getMessage()));
			}
		}
	}
	
	protected function runMyddlewareJob($ruleSlugName) {
		try{
			$session = new Session();	

			// create temp file
			$guid = uniqid();
			
			// récupération de l'exécutable PHP, par défaut c'est php
			$php = $this->container->getParameter('php');
			if (empty($php['executable'])) {
				$php['executable'] = 'php';
			}
			
			$fileTmp = $this->container->getParameter('kernel.cache_dir') . '/myddleware/job/'.$guid.'.txt';		
			$fs = new Filesystem();
			try {
				$fs->mkdir(dirname($fileTmp));
			} catch (IOException $e) {
				throw new \Exception ($this->tools->getTranslation(array('messages', 'rule', 'failed_create_directory')));
			}
			
			exec($php['executable'].' '.__DIR__.'/../../../../app/console myddleware:synchro '.$ruleSlugName.' --env=prod > '.$fileTmp.' &', $output);
			$cpt = 0;
			// Boucle tant que le fichier n'existe pas
			while (!file_exists($fileTmp)) {
				if($cpt >= 29) {
					throw new \Exception ($this->tools->getTranslation(array('messages', 'rule', 'failed_running_job')));
				}
				sleep(1);
				$cpt++;
			}
			
			// Boucle tant que l id du job n'est pas dans le fichier (écris en premier)
			$file = fopen($fileTmp, 'r');
			$firstLine = fgets($file);
			fclose($file);
			while (empty($firstLine)) {
				if($cpt >= 29) {
					throw new \Exception ($this->tools->getTranslation(array('messages', 'rule', 'failed_get_task_id')));
				}
				sleep(1);
				$file = fopen($fileTmp, 'r');
				$firstLine = fgets($file); 
				fclose($file);
				$cpt++;
			}
			
			// transform all information of the first line in an arry
			$result = explode(';',$firstLine);
			// Renvoie du message en session
			if ($result[0]) {
				$session->set('info', array('<a href="'.$this->container->get('router')->generate('task_view', array('id'=>trim($result[1]))).'" target="blank_">'.$this->tools->getTranslation(array('messages', 'rule', 'open_running_task')).'</a>.'));
			}
			else {
				$session->set('error', array($result[1].(!empty($result[2]) ? '<a href="'.$this->container->get('router')->generate('task_view', array('id'=>trim($result[2]))).'" target="blank_">'.$this->tools->getTranslation(array('messages', 'rule', 'open_running_task')).'</a>' : '')));
			}
			return $result[0];
		} catch (\Exception $e) {
			$session = new Session();
			$session->set( 'error', array($e->getMessage())); 
			return false;
		}
	}
	
	// Permet de relancer un document quelque soit son statut
	protected function rerun($id_document) {
		$session = new Session();
		$msg_error = array();
		$msg_success = array();
		$msg_info = array();
		// Récupération du statut du document
		$param['id_doc_myddleware'] = $id_document;
		$param['jobId'] = $this->jobId;
		$doc = new document($this->logger, $this->container, $this->connection, $param);
		$status = $doc->getStatus();
		// Si la règle n'est pas chargée alors on l'initialise.
		if (empty($this->ruleId)) {
			$this->ruleId = $doc->getRuleId();
			$this->setRule($this->ruleId);
			$this->setRuleRelationships();
			$this->setRuleParams();
			$this->setRuleFields();
		}
		
		// Si on a pas de job c'est que la relance est faite manuellement, il faut donc créer un job pour le flux relancé
		$manual = false;
		if (empty($this->jobId)) {
			$manual = true;
			include_once 'job.php';
			$job = new job($this->logger, $this->container, $this->connection);
			if (!$job->initJob($this->rule['rule_name_slug'].' '.$id_document)) {
				$session->set( 'error', array($job->message));
				return null;
			}
			else {
				$this->jobId = $job->id;
			}
		}
	
		$response[$id_document] = false;
		// On lance des méthodes différentes en fonction du statut en cours du document et en fonction de la réussite ou non de la fonction précédente
		if (in_array($status,array('New','Filter_KO'))) {
			$response = $this->filterDocuments(array(array('id' => $id_document)));
			if ($response[$id_document] === true) {
				$msg_success[] = 'Transfer id '.$id_document.' : Status change => Filter_OK';
			}
			elseif ($response[$id_document] == -1) {
				$msg_info[] = 'Transfer id '.$id_document.' : Status change => Filter';
			}
			else {
				$msg_error[] = 'Transfer id '.$id_document.' : Error, status transfer => Filter_KO';
			}
		}
		if ($response[$id_document] === true || in_array($status,array('Filter_OK','Predecessor_KO'))) {
			$response = $this->ckeckPredecessorDocuments(array(array('id' => $id_document)));
			if ($response[$id_document] === true) {
				$msg_success[] = 'Transfer id '.$id_document.' : Status change => Predecessor_OK';
			}
			else {
				$msg_error[] = 'Transfer id '.$id_document.' : Error, status transfer => Predecessor_KO';
			}
		}
		if ($response[$id_document] === true || in_array($status,array('Predecessor_OK','Relate_KO'))) {
			$response = $this->ckeckParentDocuments(array(array('id' => $id_document)));
			if ($response[$id_document] === true) {
				$msg_success[] = 'Transfer id '.$id_document.' : Status change => Relate_OK';
			}
			else {
				$msg_error[] = 'Transfer id '.$id_document.' : Error, status transfer => Relate_KO';
			}
		}
		if ($response[$id_document] === true || in_array($status,array('Relate_OK','Error_transformed'))) {
			$response = $this->transformDocuments(array(array('id' => $id_document)));
			if ($response[$id_document] === true) {
				$msg_success[] = 'Transfer id '.$id_document.' : Status change : Transformed';
			}
			else {
				$msg_error[] = 'Transfer id '.$id_document.' : Error, status transfer : Error_transformed';
			}
		}
		if ($response[$id_document] === true || in_array($status,array('Transformed','Error_history'))) {
			$response = $this->getTargetDataDocuments(array(array('id' => $id_document)));
			if ($response[$id_document] === true) {
				if ($this->rule['rule_mode'] == 'S') {
					$msg_success[] = 'Transfer id '.$id_document.' : Status change : Send';
				}
				else {
					$msg_success[] = 'Transfer id '.$id_document.' : Status change : '.$response['doc_status'];
				}
			}
			else {
				$msg_error[] = 'Transfer id '.$id_document.' : Error, status transfer : Error_history';
			}
		}
		// Si la règle est en mode recherche alors on n'envoie pas de données
		// Si on a un statut compatible ou si le doc vient de passer dans l'étape précédente et qu'il n'est pas no_send alors on envoie les données
		if (
				$this->rule['rule_mode'] != 'S'
			&& (
					in_array($status,array('Ready_to_send','Error_sending'))
				|| (
						$response[$id_document] === true 	
					&& (
							empty($response['doc_status'])
						|| (
								!empty($response['doc_status'])
							&& $response['doc_status'] != 'No_send'
						)
					)
				)
			)
		){
			$response = $this->sendTarget('',$id_document);		
			if (
					!empty($response[$id_document]['id']) 
				&&	empty($response[$id_document]['error'])
			) {
				$msg_success[] = 'Transfer id '.$id_document.' : Status change : Send';			
			}
			else {
				$msg_error[] = 'Transfer id '.$id_document.' : Error, status transfer : Error_sending. '.$response[$id_document]['error'];				
			}
		}		
			
		// Si le job est manuel alors on clôture le job
		if ($manual) {
			if (!$job->closeJob()) {
				$msg_error[] = 'Failed to update the job ('.$job->id.') : '.$job->message.'</error>';
			}
			if (!empty($msg_error)) {
				$session->set( 'error', $msg_error);
			}
			if (!empty($msg_success)) {
				$session->set( 'success', $msg_success);
			}
			if (!empty($msg_info)) {
				$session->set( 'info', $msg_info);
			}
		}
		return $msg_error;
	}
	
	protected function clearSendData($sendData) {
		if (!empty($sendData)) {
			foreach($sendData as $key => $value){
				unset($value['source_date_modified']);
				unset($value['id_doc_myddleware']);
				$sendData[$key] = $value;
			}
			return $sendData;
		}
	}	
	
	protected function sendTarget($type, $documentId = null) {
		// Permet de charger dans la classe toutes les relations de la règle
		$response = array();
		$response['error'] = '';

		// Le type peut-être vide das le cas d'un relancement de flux après une erreur
		if (empty($type)) {
			$documentData = $this->getDocumentData($documentId);
			if (!empty($documentData['type'])) {
				$type = $documentData['type'];
			}
		}
		
		// Récupération du contenu de la table target pour tous les documents à envoyer à la cible
		$send['data'] = $this->getSendDocuments($type, $documentId);
		$send['module'] = $this->rule['rule_module_target'];
		$send['ruleId'] = $this->rule['rule_id'];
		$send['rule'] = $this->rule;
		$send['ruleFields'] = $this->ruleFields;
		$send['ruleParams'] = $this->ruleParams;
		$send['ruleRelationships'] = $this->ruleRelationships;
		$send['fieldsType'] = $this->fieldsType;
		$send['jobId'] = $this->jobId;
		
		// Si des données sont prêtes à être créées
		if (!empty($send['data'])) {
			// Connexion à la cible
			$connect = $this->connexionSolution('target');				
			if ($connect === true) {
				// Création des données dans la cible
				if ($type == 'C') {
					// Permet de vérifier que l'on ne va pas créer un doublon dans la cible
					$send['data'] = $this->checkDuplicate($send['data']);
					$send['data'] = $this->clearSendData($send['data']);
					$response = $this->solutionTarget->create($send);
				}
				// Modification des données dans la cible
				elseif ($type == 'U') {
					$send['data'] = $this->clearSendData($send['data']);
					// permet de récupérer les champ d'historique, nécessaire pour l'update de SAP par exemple
					$send['dataHistory'] = $this->getSendDocuments($type, $documentId, 'history');
					$send['dataHistory'] = $this->clearSendData($send['dataHistory']);
					$response = $this->solutionTarget->update($send);
				}
				else {
					$response[$documentId] = false;
					$doc->setMessage('Type transfer '.$type.' unknown. ');
				}
			}
			else {
				$response[$documentId] = false;
				$response['error'] = $connect['error'];
			}
		}
		return $response;
	}
	
	protected function checkDuplicate($transformedData) {
		// Traitement si présence de champ duplicate
		if (empty($this->ruleParams['duplicate_fields'])) {
			return $transformedData;
		}
		
		$duplicate_fields = explode(';',$this->ruleParams['duplicate_fields']);
		$nameIdTarget = "id_".$this->rule['rule_name_slug']."_".$this->rule['rule_version']."_target";
		$searchDuplicate = array();
		// Boucle sur chaque donnée qui sera envoyée à la cible
		foreach ($transformedData AS $rowTransformedData) {
			// Stocke la valeur des champs duplicate concaténée
			$concatduplicate = '';

			// Récupération des valeurs de la source pour chaque champ de recherche
			foreach($duplicate_fields as $duplicate_field) {
				$concatduplicate .= $rowTransformedData[$duplicate_field];
			}
			$searchDuplicate[$rowTransformedData[$nameIdTarget]] = array('concatKey' => $concatduplicate, 'source_date_modified' => $rowTransformedData['source_date_modified']);
		}

		// Recherche de doublons dans le tableau searchDuplicate
		// Obtient une liste de colonnes
		foreach ($searchDuplicate as $key => $row) {
			$concatKey[$key]  = $row['concatKey'];
			$source_date_modified[$key] = $row['source_date_modified'];
		}

		// Trie les données par volume décroissant, edition croissant
		// Ajoute $data en tant que dernier paramètre, pour trier par la clé commune
		array_multisort($concatKey, SORT_ASC, $source_date_modified, SORT_ASC, $searchDuplicate);
				
		// Si doublon charge on charge les documents doublons, on récupère les plus récents et on les passe à transformed sans les envoyer à la cible. 
		// Le plus ancien est envoyé.
		$previous = '';	
		foreach ($searchDuplicate as $key => $value) {
			if (empty($previous)) {
				$previous = $value['concatKey'];
				continue;
			}
			// Si doublon
			if ($value['concatKey'] == $previous) {
				$param['id_doc_myddleware'] = $key;
				$param['jobId'] = $this->jobId;
				$doc = new document($this->logger, $this->container, $this->connection, $param);
				$doc->setMessage('Failed to send document because this record is already send in another document. To prevent create duplicate data in the target system, this document will be send in the next job.');
				$doc->setTypeError('W');
				$doc->updateStatus('Transformed');
				// Suppression du document dans l'envoi
				unset($transformedData[$key]);
			}
			$previous = $value['concatKey'];
		}
		
		if (!empty($transformedData)) {
			return $transformedData;
		}
		return null;
	}
	
	protected function selectDocuments($status, $type = '') {
		try {					
			$query_documents = "	SELECT * 
									FROM Documents 
									WHERE 
											rule_id = :ruleId
										AND status = :status
									ORDER BY Documents.source_date_modified ASC	
									LIMIT $this->limit
								";							
			$stmt = $this->connection->prepare($query_documents);
			$stmt->bindValue(":ruleId", $this->ruleId);
			$stmt->bindValue(":status", $status);
		    $stmt->execute();	   				
			return $stmt->fetchAll();
		} catch (\Exception $e) {
			$this->logger->error( 'Error : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )' );
		}
	}
	
	// Permet de récupérer les données d'un document
	protected function getDocumentData($documentId) {
		try {			
			$query_document = "SELECT * FROM Documents WHERE id = :documentId";
			$stmt = $this->connection->prepare($query_document);
			$stmt->bindValue(":documentId", $documentId);
		    $stmt->execute();	   				
			$document = $stmt->fetch();	   				
			if (!empty($document)) {
				return $document;
			}
			else {
				return false;
			}
		} catch (\Exception $e) {
			$this->logger->error( 'Error : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )' );
		}
	}
	
	protected function getSendDocuments($type,$documentId,$table = 'target') {
		$nameId = "id_".$this->rule['rule_name_slug']."_".$this->rule['rule_version']."_".$table; 
		$tableRule = "z_".$this->rule['rule_name_slug']."_".$this->rule['rule_version']."_".$table;
		
		// Si un document est en paramètre alors on filtre la requête sur le document 
		if (!empty($documentId)) {
			$documentFilter = " Documents.id = '$documentId'";
		}
		// Sinon on récupère tous les documents élligible pour l'envoi
		else {
			$documentFilter = 	"	Documents.rule_id = '$this->ruleId'
								AND Documents.status = 'Ready_to_send'
								AND Documents.type = '$type' ";
		}
		// Sélection de tous les documents au statut transformed en attente de création pour la règle en cours
		$sql = "SELECT $tableRule.* , Documents.id id_doc_myddleware, Documents.target_id, Documents.source_date_modified
				FROM Documents
					INNER JOIN $tableRule  
						ON Documents.id = $tableRule.$nameId
				WHERE $documentFilter 
				ORDER BY Documents.source_date_modified ASC
				LIMIT $this->limit";
		$stmt = $this->connection->prepare($sql);
		$stmt->execute();	    
		$documents = $stmt->fetchAll();

		foreach ($documents as $document) {
			$return[$document['id_doc_myddleware']] = $document;
		}

		if (!empty($return)) {
			return $return;
		}
		return null;
	}

	// Permet de charger tous les champs de la règle
	protected function setRuleFields() {
		
		try {	
			// Lecture des champs de la règle
			$sqlFields = "SELECT * 
							FROM RuleFields 
							WHERE rule_id = :ruleId";
			$stmt = $this->connection->prepare($sqlFields);
			$stmt->bindValue(":ruleId", $this->ruleId);
		    $stmt->execute();	   				
			$this->ruleFields = $stmt->fetchAll();
		
			if($this->ruleFields) {
				foreach ($this->ruleFields as $RuleField) { 
					// Plusieurs champs source peuvent être utilisé pour un seul champ cible
					$fields = explode(";", $RuleField['rulef_source_field_name']);
					foreach ($fields as $field) {
						$this->sourceFields[] = ltrim($field);
					}
					$this->targetFields[] = ltrim($RuleField['rulef_target_field_name']);
				}			
			}
			
			// Lecture des relations de la règle
			if($this->ruleRelationships) {
				foreach ($this->ruleRelationships as $ruleRelationship) { 
					$this->sourceFields[] = ltrim($ruleRelationship['rrs_field_name_source']);
					$this->targetFields[] = ltrim($ruleRelationship['rrs_field_name_target']);
				}			
			} 

			// Dédoublonnage des tableaux
			if (!empty($this->targetFields)) {
				$this->targetFields = array_unique($this->targetFields);
			}
			if (!empty($this->sourceFields)) {
				$this->sourceFields = array_unique($this->sourceFields); 				
			}
			
			// Récupération des types de champs de la source
			$sourceTable = "z_".$this->rule['rule_name_slug']."_".$this->rule['rule_version']."_source";
			$sqlParams = "SHOW COLUMNS FROM ".$sourceTable;
			$stmt = $this->connection->prepare($sqlParams);
		    $stmt->execute();	   				
			$sourceFields = $stmt->fetchAll();
			if (!empty($sourceFields)) {
				foreach ($sourceFields as $sourceFiled) {
					$this->fieldsType['source'][$sourceFiled['Field']] = $sourceFiled;
				}
			}
			
			// Récupération des types de champs de la target
			$targetTable = "z_".$this->rule['rule_name_slug']."_".$this->rule['rule_version']."_target";
			$sqlParams = "SHOW COLUMNS FROM ".$targetTable;
			$stmt = $this->connection->prepare($sqlParams);
		    $stmt->execute();	   				
			$targetFileds = $stmt->fetchAll();
			if (!empty($targetFileds)) {
				foreach ($targetFileds as $targetField) {
					$this->fieldsType['target'][$targetField['Field']] = $targetField;
				}
			}			
		} catch (\Exception $e) {
			$this->logger->error( 'Error : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )' );
		}
		
	}
	
	// Permet de charger tous les paramètres de la règle
	protected function setRuleParams() {
			
		try {
			$sqlParams = "SELECT * 
							FROM RuleParams 
							WHERE rule_id = :ruleId";
			$stmt = $this->connection->prepare($sqlParams);
			$stmt->bindValue(":ruleId", $this->ruleId);
		    $stmt->execute();	   				
			$ruleParams = $stmt->fetchAll();
			if($ruleParams) {
				foreach ($ruleParams as $ruleParam) {
					$this->ruleParams[$ruleParam['rulep_name']] = ltrim($ruleParam['rulep_value']);
				}			
			}			
		} catch (\Exception $e) {
			$this->logger->error( 'Error : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )' );
		}
	}	

	
	
	// Permet de charger toutes les relations de la règle
	protected function setRuleRelationships() {
		try {					
			$sqlFields = "SELECT * 
							FROM RuleRelationShips 
							WHERE 
									rule_id = :ruleId
								AND rule_id IS NOT NULL";
			$stmt = $this->connection->prepare($sqlFields);
			$stmt->bindValue(":ruleId", $this->ruleId);
		    $stmt->execute();	   				
			$this->ruleRelationships = $stmt->fetchAll();
		} catch (\Exception $e) {
			$this->logger->error( 'Error : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )' );
		}
	}
    
	// Permet de charger toutes les filtres de la règle
	protected function setRuleFilters() {
		try {					
			$sqlFields = "SELECT * 
							FROM RuleFilters 
							WHERE 
								rule_id = :ruleId";
			$stmt = $this->connection->prepare($sqlFields);
			$stmt->bindValue(":ruleId", $this->ruleId);
		    $stmt->execute();	   				
			$this->ruleFilters= $stmt->fetchAll();
		} catch (\Exception $e) {
			$this->logger->error( 'Error : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )' );
		}
	}
    
	// Parametre de la règle choix utilisateur
	/* 
	array(
		'id' 		=> 'datereference',
		'name' 		=> 'datereference',
		'required'	=> true,
		'type'		=> 'text',
		'label' => 'solution.params.dateref',
		'readonly' => true
	),	*/		
	public static function getFieldsParamUpd() {         
	   return array();
	}
	
	// Parametre de la règle obligation du système par défaut
	public static function getFieldsParamDefault($idSolutionSource = '',$idSolutionTarget = '') {
		return array(
			'active' => false,
			'RuleParams' => array(
				'rate' => '5',
				'delete' => '60',
				'datereference' => date('Y-m-d').' 00:00:00'			
			),
		);		
	}

	// Parametre de la règle en modification dans la fiche
	public static function getFieldsParamView($idRule = '') { 
	   return array(	 
			array(
				'id' 		=> 'datereference',
				'name' 		=> 'datereference',
				'required'	=> true,
				'type'		=> 'text',
				'label' => 'solution.params.dateref'
			),
			array( // clear data
				'id' 		=> 'delete',
				'name' 		=> 'delete',
				'required'	=> false,
				'type'		=> 'option',
				'label' => 'solution.params.delete',
				'option'	=> array (
								'0' => 'solution.params.0_day',
								'1' => 'solution.params.1_day',
								'7' => 'solution.params.7_day',
								'14' => 'solution.params.14_day',
								'30' => 'solution.params.30_day',
								'60' => 'solution.params.60_day'
							),
			) 		
		);
	}

}


/* * * * * * * *  * * * * * *  * * * * * * 
	si custom file exist alors on fait un include de la custom class
 * * * * * *  * * * * * *  * * * * * * * */
$file = __DIR__.'/../Custom/Classes/rule.php';
if(file_exists($file)){
	require_once($file);
}
else {
	//Sinon on met la classe suivante
	class rule extends rulecore {
		
	}
}
?>