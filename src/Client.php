<?php

namespace GuMqtt;

use GuMqtt\Protocols\Mqtt;
use phpDocumentor\Reflection\Types\False_;

class Client {
	/**
	 * STATE_INITIAL.
	 */
	const STATE_INITIAL = 1;

	/**
	 * STATE_CONNECTING
	 */
	const STATE_CONNECTING = 2;

	/**
	 * STATE_WAITCONACK
	 */
	const STATE_WAITCONACK = 3;

	/**
	 * STATE_ESTABLISHED
	 */
	const STATE_ESTABLISHED = 4;

	/**
	 * STATE_DISCONNECT
	 */
	const STATE_DISCONNECT = 5;

	/**
	 * DEFAULT_CLIENT_ID_PREFIX
	 */
	const DEFAULT_CLIENT_ID_PREFIX = 'swoole-mqtt-client';

	/**
	 * MAX_TOPIC_LENGTH
	 */
	const MAX_TOPIC_LENGTH = 65535;

	/**
	 * @var callable
	 */
	public $onConnect = null;

	/**
	 * @var callable
	 */
	public $onReconnect = null;

	/**
	 * @var callable
	 */
	public $onMessage = null;

	/**
	 * @var callable
	 */
	public $onClose = null;

	/**
	 * @var callable
	 */
	public $onError = null;

	/**
	 * @var int
	 */
	protected $_state = 1;

	/**
	 * @var int
	 */
	protected $_messageId = 1;

	/**
	 * @var string
	 */
	protected $_remoteHost = '';

	/**
	 * @var string
	 */
	protected $_remotePort = '';

	/**
	 * @var AsyncTcpConnection
	 */
	protected $_connection = null;

	/**
	 * @var boolean
	 */
	protected $_firstConnect = true;

	/**
	 * ['topic'=>qos, ...]
	 * @var array
	 */
	protected $_resubscribeTopics = array();

	/**
	 * @var int
	 */
	protected $_checkConnectionTimeoutTimer = 0;

	/**
	 * @var int
	 */
	protected $_pingTimer = 0;

	/**
	 * @var bool
	 */
	protected $_recvPingResponse = true;

	/**
	 * @var bool
	 */
	protected $_doNotReconnect = false;

	/**
	 * @var array
	 */
	protected $_outgoing = array();

	/**
	 * 错误码
	 * @var array
	 */
	protected static $_errorCodeStringMap = array(
		1 => 'Connection Refused, unacceptable protocol version',
		2 => 'Connection Refused, identifier rejected',
		3 => 'Connection Refused, Server unavailable',
		4 => 'Connection Refused, bad user name or password',
		5 => 'Connection Refused, not authorized',
		100 => 'Connection closed',
		101 => 'Connection timeout',
		102 => 'Connection fail',
		103 => 'Connection buffer full and close connection',
		140 => 'No connection to broker',
		240 => 'Invalid topic',
		241 => 'Invalid qos',
	);

	/**
	 * 配置参数
	 * @var array
	 */
	protected $_options = array(
		'clean_session' => 1,
		// set to 0 to receive QoS 1 and 2 messages while offline
		'username' => '',
		// the username required by your broker
		'password' => '',
		// the password required by your broker; unit: second
		'keepalive' => 60,
		// default 50 seconds, set to 0 to disable
		'protocol_name' => 'MQTT',
		// protocol name MQTT or MQIsdp
		'protocol_level' => 4,
		// protocol level, MQTT is 4 and MQIsdp is 3
		'reconnect_period' => 15,
		// reconnect period default 1 second, set to 0 to disable
		'connect_timeout' => 15,
		// 30 seconds, time to wait before a CONNACK is received
		'resubscribe' => true,
		// default true, if connection is broken and reconnects, subscribed topics are automatically subscribed again.
		'bindto' => '',
		// bindto option, used to specify the IP address that PHP will use to access the network
		'ssl' => false,
		// ssl context, see https://wiki.swoole.com/wiki/page/p-client_setting.html
		'debug' => true,
		// debug
		'socket_buffer_size' => 1024 * 1024 * 2,
		// 2M缓存区
		'package_max_length' => 2000000,
		// 协议最大长度
	);

	/**
	 * Client constructor.
	 * @param $address
	 * @param array $options
	 */
	public function __construct( string $host, int $port, $options = array() ) {
		$this->_remoteHost = $host;
		$this->_remotePort = $port;
		$this->setOptions( $options );

		$context = array();

		if( $this->_options['ssl'] && is_array( $this->_options['ssl'] ) ){
			$context = array_merge( $context, $this->_options['ssl'] );
		}
		if( $this->_options['socket_buffer_size'] ){
			$context['socket_buffer_size'] = $this->_options['socket_buffer_size'];
		}
		if( $this->_options['package_max_length'] ){
			$context['package_max_length'] = $this->_options['package_max_length'];
		}
		$this->_connection = new \Swoole\Coroutine\Client( SWOOLE_SOCK_TCP );

		$this->_connection->set( $context );

		$this->onReconnect = array(
			$this,
			'onMqttReconnect'
		);
		$this->onMessage = function () {
		};
	}

	/**
	 * 获取mqtt配置参数
	 * @return array
	 */
	public function getMqttOptions(  ):array {
		return $this->_options;
	}

	/**
	 * 获取mqtt client_id
	 * @return string|null
	 */
	public function getMqttClientId(  ):?string {
		return $this->_options['client_id'] ?? null;
	}

	/**
	 * 获取连接Client
	 * @return \Swoole\Coroutine\Client
	 */
	public function getConnection():\Swoole\Coroutine\Client {
		return $this->_connection;
	}

	/**
	 * connect
	 */
	public function connect() {
		$this->_doNotReconnect = false;
		$this->_connection->connect( $this->_remoteHost, $this->_remotePort );
		$this->setConnectionTimeout( $this->_options['connect_timeout'] );
		if( $this->_options['debug'] ){
			echo "-- Try to connect to {$this->_remoteHost}:{$this->_remotePort}", PHP_EOL;
		}
		$this->mqttConnect();
	}


	/**
	 * onConnectionConnect
	 */
	public function mqttConnect() {
		$package = array(
			'cmd' => Mqtt::CMD_CONNECT,
			'clean_session' => $this->_options['clean_session'],
			'username' => $this->_options['username'],
			'password' => $this->_options['password'],
			'keepalive' => $this->_options['keepalive'],
			'protocol_name' => $this->_options['protocol_name'],
			'protocol_level' => $this->_options['protocol_level'],
			'client_id' => $this->_options['client_id'],
		);
		if( isset( $this->_options['will'] ) ){
			$package['will'] = $this->_options['will'];
		}

		$this->_state = static::STATE_WAITCONACK;

		$this->_connection->send( Mqtt::encode( $package ) );
		// 处理连接返回信息
		$maxI = round( $this->_options['connect_timeout'], 0 ); // 等待次数（默认每次1秒等待）
		if( $maxI <= 0 ){
			$maxI = 1;
		}
		do{
			$this->loopForeverOrTimeout( 1 );
			if( --$maxI <= 0 ){
				$this->triggerError( 101 );
				break;
			}
		} while( $this->_state != static::STATE_ESTABLISHED );
		if( $this->_options['debug'] ){
			echo "↑ Tcp connection established", PHP_EOL;
			echo "↑ Send CONNECT package client_id:{$this->_options['client_id']} username:{$this->_options['username']} password:{$this->_options['password']} clean_session:{$this->_options['clean_session']} protocol_name:{$this->_options['protocol_name']} protocol_level:{$this->_options['protocol_level']}", PHP_EOL;
		}
		if( $this->_state !== static::STATE_ESTABLISHED ){
			$this->triggerError( 102 );
		}
	}

	/**
	 * 消息循环
	 * @param int $loopMaxTime
	 * @param float $recvTimeoutS recv超时时间，单位：秒 [null]默认超时，超时后执行默认动作，不返回SOCKET_ETIMEDOUT [>0]超时并返回SOCKET_ETIMEDOUT
	 * @return int [1]已处理 [0]关闭 [SOCKET_ETIMEDOUT]超时
	 * @throws
	 */
	public function loopForever( int $loopMaxTime = -1, float $recvTimeoutS = null ):int {
		$startTime = time();
		$recvTimeoutSReal = $loopMaxTime > 0 ? 1 : ( $recvTimeoutS ?? 1 );
		do{
			if( $loopMaxTime > 0 && time() - $startTime >= $loopMaxTime ){
				break;
			}
			/*			if( $this->_state === static::STATE_ESTABLISHED ){
							$this->publish( '1', '1' );
							// $this->publish( '/b1qcqqib9FN/SL_NB_MCC_01/update', '1' );
						}*/

			$data = $this->_connection->recv( $recvTimeoutSReal );
			d([
				'time',
				time() - $startTime,
				$data,
			]);
			if( $data === '' ){
				$this->close();
				return 0;
			}
			if( $data === false ){
				/*					d([
										'recv发生错误，错误代码' . $this->_connection->errMsg, $this->_connection->errCode,
										time() - $startTime,
									]);*/
				switch( $this->_connection->errCode ){
					case SOCKET_ETIMEDOUT:
						if( $recvTimeoutS !== null ){
							return SOCKET_ETIMEDOUT;
						}
						continue 2;
					case 104:
						// 104 recv发生错误，错误代码Connection reset by peer
						return 0;
					case SWOOLE_ERROR_CLIENT_NO_CONNECTION:
						return 0;
					default:
						throw new \Exception( 'recv发生错误，错误代码' . $this->_connection->errMsg, $this->_connection->errCode );
				}
			}
			//
			$this->onConnectionReceive( $data );
		} while( $loopMaxTime != 0 );
		//
		return 1;
	}

	/**
	 * 消息循环直到超时
	 * @param bool $loop
	 * @param float $recvTimeoutS recv超时时间，单位：秒
	 * @return void
	 * @throws
	 */
	public function loopForeverOrTimeout( float $recvTimeoutS = 1 ) {
		// 直到超时
		while( 1 == 1 ){
			$res = $this->loopForever( 0, $recvTimeoutS );
			if( $res === SOCKET_ETIMEDOUT ){
				break;
			}
		}
	}

	/**
	 * reconnect
	 *
	 * @param int $after
	 */
	public function reconnect( $after = 0 ) {
		$this->_doNotReconnect = false;
		$this->_connection->connect( $this->_remoteHost, $this->_remotePort );
		$this->setConnectionTimeout( $this->_options['connect_timeout'] + $after );
		if( $this->_options['debug'] ){
			echo "-- Reconnect after $after seconds", PHP_EOL;
		}
		$this->onConnectionConnect();
	}

	/**
	 * publish
	 *
	 * @param $topic
	 * @param $content
	 * @param array $options
	 * @param callable $callback
	 */
	public function publish( $topic, $content, $options = array(), $callback = null ) {
		if( $this->checkDisconnecting( $callback ) ){
			return;
		}

		static::isValidTopic( $topic );

		$qos = 0;
		$retain = 0;
		$dup = 0;
		if( isset( $options['qos'] ) ){
			$qos = $options['qos'];
			if( $this->checkInvalidQos( $qos, $callback ) ){
				return;
			}
		}
		if( ! empty( $options['retain'] ) ){
			$retain = 1;
		}
		if( ! empty( $options['dup'] ) ){
			$dup = 1;
		}

		$package = array(
			'cmd' => Mqtt::CMD_PUBLISH,
			'topic' => $topic,
			'content' => $content,
			'retain' => $retain,
			'qos' => $qos,
			'dup' => $dup,
		);

		if( $qos ){
			$package['message_id'] = $this->incrMessageId();
			if( $callback ){
				$this->_outgoing[ $package['message_id'] ] = $callback;
			}
		}

		if( $this->_options['debug'] ){
			$message_id = isset( $package['message_id'] ) ? $package['message_id'] : '';
			echo "↑ Send PUBLISH package, topic:$topic content:$content retain:$retain qos:$qos dup:$dup message_id:$message_id", PHP_EOL;
		}

		$this->sendPackage( $package );
	}

	/**
	 * subscribe
	 *
	 * @param $topic
	 * @param array $options
	 * @param callable $callback
	 */
	public function subscribe( $topic, $options = array(), $callback = null ) {
		if( $this->checkDisconnecting( $callback ) ){
			return;
		}

		if( is_array( $topic ) ){
			$topics = $topic;
		} else{
			$qos = ! is_callable( $options ) && isset( $options['qos'] ) ? $options['qos'] : 0;
			$topics = array( $topic => $qos );
		}

		$args = func_get_args();

		$callback = end( $args );

		if( ! is_callable( $callback ) ){
			$callback = null;
		}
		if( $invalid_topic = static::validateTopics( $topics ) ){
			$this->triggerError( 240, $callback );
			return;
		}
		if( $this->_options['resubscribe'] ){
			$this->_resubscribeTopics += $topics;
		}

		$package = array(
			'cmd' => Mqtt::CMD_SUBSCRIBE,
			'topics' => $topics,
			'message_id' => $this->incrMessageId(),
		);

		if( $this->_options['debug'] ){
			echo "↑ Send SUBSCRIBE package, topic:" . implode( ',', array_keys( $topics ) ) . " message_id:{$package['message_id']}", PHP_EOL;
		}
		$this->sendPackage( $package );
		if( $callback ){
			$this->_outgoing[ $package['message_id'] ] = function ( $exception, $codes = array() ) use ( $callback, $topics ) {
				if( $exception ){
					call_user_func( $callback, $exception, array() );
					return;
				}
				$granted = array();
				$topics = array_keys( $topics );
				foreach( $topics as $key => $topic ){
					$granted[ $topic ] = $codes[ $key ];
				}
				if( $callback ){
					call_user_func( $callback, null, $granted );
				}
			};
		}
	}

	/**
	 * unsubscribe
	 *
	 * @param $topic
	 */
	public function unsubscribe( $topic, $callback = null ) {
		if( $this->checkDisconnecting( $callback ) ){
			return;
		}
		$topics = is_array( $topic ) ? $topic : array( $topic );
		if( $invalid_topic = static::validateTopics( $topics ) ){
			$this->triggerError( 240 );
			return;
		}
		foreach( $topics as $topic ){
			if( isset( $this->_resubscribeTopics[ $topic ] ) ){
				unset( $this->_resubscribeTopics[ $topic ] );
			}
		}
		$package = array(
			'cmd' => Mqtt::CMD_UNSUBSCRIBE,
			'topics' => $topics,
			'message_id' => $this->incrMessageId(),
		);
		if( $callback ){
			$this->_outgoing[ $package['message_id'] ] = $callback;
		}
		if( $this->_options['debug'] ){
			echo "↑ Send UNSUBSCRIBE package, topic:" . implode( ',', $topics ) . " message_id:{$package['message_id']}", PHP_EOL;
		}
		$this->sendPackage( $package );
	}

	/**
	 * isConnected
	 */
	public function isConnected() {
		return $this->_connection->isConnected();
	}

	/**
	 * sendPackage
	 *
	 * @param $package
	 */
	protected function sendPackage( $package ) {
		if( $this->checkDisconnecting() ){
			return;
		}

		if( $this->_options['bindto'] ){
			return $this->_connection->sendto( Mqtt::encode( $package ) );
		}
		return $this->_connection->send( Mqtt::encode( $package ) );
	}

	/**
	 * 关闭mqtt
	 */
	public function close() {
		$this->_doNotReconnect = true;
		if( $this->_options['debug'] ){
			echo "-- Connection->close() called", PHP_EOL;
		}
		$this->onConnectionClose();
		$this->closeConnection();
	}

	/**
	 * 断开连接
	 */
	public function closeConnection() {
		if( $this->_connection->isConnected() ){
			$this->_connection->close();
		}
	}


	/**
	 * onMqttReconnect
	 */
	public function onMqttReconnect() {
		echo "onMqttReconnect\n";
		if( $this->_options['clean_session'] && $this->_options['resubscribe'] && $this->_resubscribeTopics ){
			$package = array(
				'cmd' => Mqtt::CMD_SUBSCRIBE,
				'topics' => $this->_resubscribeTopics,
				'message_id' => $this->incrMessageId(),
			);
			$this->sendPackage( $package );
			if( $this->_options['debug'] ){
				echo "↑ Send SUBSCRIBE(Resubscribe) package topics:" . implode( ',', array_keys( $this->_resubscribeTopics ) ) . " message_id:{$package['message_id']}", PHP_EOL;
			}
		}
	}

	/**
	 * onConnectionReceive
	 */
	public function onConnectionReceive( $data ) {
		// 拆包
		$dataArray = Mqtt::splitBuffer( $data );
		foreach( $dataArray as $dataValue ){
			$this->handleConnectionReceive( $dataValue );
		}
	}

	/**
	 * 数据处理
	 * @param $data
	 */
	public function handleConnectionReceive( $data ) {
		$data = Mqtt::decode( $data );
		if( empty( $data ) ){
			return;
		}
		$cmd = $data['cmd'];
		switch( $cmd ){
			case Mqtt::CMD_CONNACK:
				$code = $data['code'];
				if( $code != 0 ){
					$message = static::$_errorCodeStringMap[ $code ];
					if( $this->_options['debug'] ){
						echo "↓ Recv CONNACK package but get error " . $message . PHP_EOL;
					}
					$this->triggerError( $code );
					$this->_connection->close();
					return;
				}
				if( $this->_options['debug'] ){
					echo "↓ Recv CONNACK package, MQTT connect success", PHP_EOL;
				}
				$this->_state = static::STATE_ESTABLISHED;
				if( $this->_firstConnect ){
					if( $this->onConnect ){
						call_user_func( $this->onConnect, $this );
					}
					$this->_firstConnect = false;
				} else{
					if( $this->onReconnect ){
						call_user_func( $this->onReconnect, $this );
					}
				}
				$this->setPingTimer( $this->_options['keepalive'] );
				$this->cancelConnectionTimeout();
				return;
			case Mqtt::CMD_PUBLISH:
				$topic = $data['topic'];
				$content = $data['content'];
				$qos = $data['qos'];
				$message_id = isset( $data['message_id'] ) ? $data['message_id'] : '';
				if( $this->_options['debug'] ){
					echo "↓ Recv PUBLISH package, message_id:$message_id qos:$qos topic:$topic content:$content", PHP_EOL;
				}
				call_user_func( $this->onMessage, $topic, $content, $this );
				// Connection may be closed in onMessage callback.
				if( $this->_state !== static::STATE_ESTABLISHED ){
					return;
				}
				switch( $qos ){
					case 0:
						break;
					case 1:
						if( $this->_options['debug'] ){
							echo "↑ Send PUBACK package, message_id:$message_id", PHP_EOL;
						}
						$this->sendPackage( array(
							'cmd' => Mqtt::CMD_PUBACK,
							'message_id' => $message_id,
						) );
						break;
					case 2:
						if( $this->_options['debug'] ){
							echo "↑ Send PUBREC package, message_id:$message_id", PHP_EOL;
						}
						$this->sendPackage( array(
							'cmd' => Mqtt::CMD_PUBREC,
							'message_id' => $message_id,
						) );
				}
				return;
			case Mqtt::CMD_PUBREC:
				$message_id = $data['message_id'];
				if( $this->_options['debug'] ){
					echo "↑ Recv PUBREC package, message_id:$message_id", PHP_EOL;
					echo "↓ Send PUBREL package, message_id:$message_id", PHP_EOL;
				}
				$this->sendPackage( array(
					'cmd' => Mqtt::CMD_PUBREL,
					'message_id' => $data['message_id'],
				) );
				break;
			case Mqtt::CMD_PUBREL:
				$message_id = $data['message_id'];
				if( $this->_options['debug'] ){
					echo "↓ Recv PUBREL package, message_id:$message_id", PHP_EOL;
					echo "↑ Send PUBCOMP package, message_id:$message_id", PHP_EOL;
				}
				$this->sendPackage( array(
					'cmd' => Mqtt::CMD_PUBCOMP,
					'message_id' => $message_id,
				) );
				break;
			case Mqtt::CMD_PUBACK:
			case Mqtt::CMD_PUBCOMP:
				$message_id = $data['message_id'];
				if( $this->_options['debug'] ){
					echo "↓ Recv " . ( $cmd == Mqtt::CMD_PUBACK ? 'PUBACK' : 'PUBCOMP' ) . " package, message_id:$message_id", PHP_EOL;
				}
				if( ! empty( $this->_outgoing[ $message_id ] ) ){
					if( $this->_options['debug'] ){
						echo "-- Trigger PUB callback for message_id:$message_id", PHP_EOL;
					}
					$callback = $this->_outgoing[ $message_id ];
					unset( $this->_outgoing[ $message_id ] );
					call_user_func( $callback, null );
				}
				break;
			case Mqtt::CMD_SUBACK:
			case Mqtt::CMD_UNSUBACK:
				$message_id = $data['message_id'];
				if( $this->_options['debug'] ){
					echo "↓ Recv " . ( $cmd == Mqtt::CMD_SUBACK ? 'SUBACK' : 'UNSUBACK' ) . " package, message_id:$message_id", PHP_EOL;
				}
				$callback = isset( $this->_outgoing[ $message_id ] ) ? $this->_outgoing[ $message_id ] : null;
				unset( $this->_outgoing[ $message_id ] );
				if( $callback ){
					if( $this->_options['debug'] ){
						echo "-- Trigger " . ( $cmd == Mqtt::CMD_SUBACK ? 'SUB' : 'UNSUB' ) . " callback for message_id:$message_id", PHP_EOL;
					}
					if( $cmd === Mqtt::CMD_SUBACK ){
						call_user_func( $callback, null, $data['codes'] );
					} else{
						call_user_func( $callback, null );
					}
				}
				break;
			case Mqtt::CMD_PINGRESP:
				$this->_recvPingResponse = true;
				if( $this->_options['debug'] ){
					echo "↓ Recv PINGRESP package", PHP_EOL;
				}
				break;
			default:
				echo "unknow cmd";
		}
	}

	/**
	 * onConnectionBufferFull
	 */
	public function onConnectionBufferFull() {
		if( $this->_options['debug'] ){
			echo "-- Connection buffer full and close connection", PHP_EOL;
		}
		$this->triggerError( 103 );
		$this->_connection->close();
	}

	/**
	 * onConnectionClose
	 */
	private function onConnectionClose() {
		if( $this->_options['debug'] ){
			echo "-- Connection closed", PHP_EOL;
		}
		$this->cancelPingTimer();
		$this->cancelConnectionTimeout();
		$this->_recvPingResponse = true;
		$this->_state = static::STATE_DISCONNECT;
		if( ! $this->_doNotReconnect && $this->_options['reconnect_period'] > 0 ){
			$this->reConnect( $this->_options['reconnect_period'] );
		}

		$this->flushOutgoing();

		if( $this->onClose ){
			call_user_func( $this->onClose, $this );
		}
	}

	/**
	 * incrMessageId
	 *
	 * @return int
	 */
	protected function incrMessageId() {
		$message_id = $this->_messageId++;
		if( $message_id >= 65535 ){
			$this->_messageId = 1;
		}
		return $message_id;
	}

	/**
	 * checkInvalidQos
	 *
	 * @param $qos
	 * @return boolean
	 */
	protected function checkInvalidQos( $qos, $callback = null ) {
		if( $qos !== 0 && $qos !== 1 && $qos !== 2 ){
			$this->triggerError( 241, $callback );
			return true;
		}
		return false;
	}

	/**
	 * isValidTopic
	 *
	 * @param $topic
	 * @return boolean
	 */
	protected static function isValidTopic( $topic ) {
		if( ! static::isString( $topic ) ){
			return false;
		}
		$topic_length = strlen( $topic );
		if( $topic_length > static::MAX_TOPIC_LENGTH ){
			return false;
		}
		return true;
	}

	/**
	 * validateTopics
	 *
	 * @param $topics
	 * @return null|string
	 */
	protected static function validateTopics( $topics ) {
		if( empty( $topics ) ){
			return 'array()';
		}
		foreach( $topics as $topic ){
			if( ! static::isValidTopic( $topic ) ){
				return $topic;
			}
		}
		return null;
	}

	/**
	 * createRandomClientId
	 *
	 * @return string
	 */
	protected function createRandomClientId() {
		mt_srand();
		return static::DEFAULT_CLIENT_ID_PREFIX . '-' . mt_rand();
	}

	/**
	 * is string.
	 * @param $string
	 * @return bool
	 */
	protected static function isString( $string ) {
		return ( is_string( $string ) || is_integer( $string ) ) && strlen( $string ) > 0;
	}

	/**
	 * addCheckTimeoutTimer
	 * @param int $timeout 超时；秒
	 */
	protected function setConnectionTimeout( $timeout ) {
		// $timeout = 10;
		$this->cancelConnectionTimeout();
		$this->_checkConnectionTimeoutTimer = \Swoole\Timer::after( $timeout * 1000, array(
			$this,
			'checkConnectTimeout'
		) );
	}

	/**
	 * cancelConnectionTimeout
	 */
	protected function cancelConnectionTimeout() {
		if( $this->_checkConnectionTimeoutTimer ){
			\Swoole\Timer::clear( $this->_checkConnectionTimeoutTimer );
			$this->_checkConnectionTimeoutTimer = 0;
		}
	}

	/**
	 * setPingTimer
	 */
	protected function setPingTimer( $ping_interval ) {
		$this->cancelPingTimer();
		$connection = $this->_connection;
		$this->_pingTimer = \Swoole\Timer::after( $ping_interval * 1000, function () use ( $connection, $ping_interval ) {
			/*
			if( ! $this->_recvPingResponse ){
				if( $this->_options['debug'] ){
					echo "↓ Recv PINGRESP timeout", PHP_EOL;
					echo "↑ Close connection", PHP_EOL;
				}
				$connection->close();
				return;
			}
			*/
			if( $this->_options['debug'] ){
				echo "↑ Send PINGREQ package", PHP_EOL;
			}
			$this->_recvPingResponse = false;
			$connection->send( Mqtt::encode( array( 'cmd' => Mqtt::CMD_PINGREQ ) ) );
			//
			$this->setPingTimer( $ping_interval );
		} );
	}

	/**
	 * cancelPingTimer
	 */
	protected function cancelPingTimer() {
		if( $this->_pingTimer ){
			\Swoole\Timer::clear( $this->_pingTimer );
			$this->_pingTimer = 0;
		}
	}

	/**
	 * checkConnectTimeout
	 */
	public function checkConnectTimeout() {
		d('AAAAAAAAAAAAAAAAAAAAAAAA断开AAAAAAAAAAAAAAAAAAAAAAAAA');
		if( $this->_state === static::STATE_CONNECTING || $this->_state === static::STATE_WAITCONACK ){
			$this->triggerError( 101 );
			empty( $this->_connection ) ? $this->_connection = null : $this->_connection->close();
		}
	}

	/**
	 * checkDisconnecting
	 *
	 * @param null $callback
	 * @return bool
	 */
	protected function checkDisconnecting( $callback = null ) {
		if( $this->_state !== static::STATE_ESTABLISHED ){
			$this->triggerError( 140, $callback );
			return true;
		}
		return false;
	}

	/**
	 * flushOutgoing
	 */
	protected function flushOutgoing() {
		foreach( $this->_outgoing as $message_id => $callback ){
			$this->triggerError( 100, $callback );
		}
		$this->_outgoing = array();
	}

	/**
	 * triggerError
	 *
	 * @param $exception
	 * @param $callback
	 */
	protected function triggerError( $code, $callback = null ) {
		$exception = new \Exception( static::$_errorCodeStringMap[ $code ], $code );
		if( $this->_options['debug'] ){
			echo "-- Error: " . $exception->getMessage() . PHP_EOL;
		}
		if( ! $callback ){
			$callback = $this->onError ? $this->onError : function ( $exception ) {
				echo "Mqtt client: ", $exception->getMessage(), PHP_EOL;
			};
		}
		call_user_func( $callback, $exception );
	}

	/**
	 * set options.
	 *
	 * @param $options
	 * @throws \Exception
	 */
	protected function setOptions( $options ) {
		if( isset( $options['clean_session'] ) && ! $options['clean_session'] ){
			$this->_options['clean_session'] = 0;
		}

		if( isset( $options['username'] ) ){
			if( ! static::isString( $options['username'] ) && $options['username'] != '' ){
				throw new \Exception( 'Bad username, expected string or integer but ' . gettype( $options['username'] ) . ' provided.' );
			}
			$this->_options['username'] = $options['username'];
		}

		if( isset( $options['password'] ) ){
			if( ! static::isString( $options['password'] ) && $options['password'] != '' ){
				throw new \Exception( 'Bad password, expected string or integer but ' . gettype( $options['password'] ) . ' provided.' );
			}
			$this->_options['password'] = $options['password'];
		}

		if( isset( $options['keepalive'] ) ){
			$keepalive = (int)$options['keepalive'];
			if( ! static::isString( $keepalive ) ){
				throw new \Exception( 'Bad keepalive, expected integer but ' . gettype( $keepalive ) . ' provided.' );
			}
			if( $keepalive < 0 ){
				throw new \Exception( 'Bad keepalive, expected integer which not less than 0 but ' . $keepalive . ' provided.' );
			}
			$this->_options['keepalive'] = $keepalive;
		}

		if( isset( $options['protocol_name'] ) ){
			$protocol_name = $options['protocol_name'];
			if( $protocol_name !== 'MQTT' && $protocol_name !== 'MQIsdp' ){
				throw new \Exception( 'Bad protocol_name of options, expected MQTT or MQIsdp but ' . $protocol_name . ' provided.' );
			}
			$this->_options['protocol_name'] = $protocol_name;
		}

		if( isset( $options['protocol_level'] ) ){
			$protocol_level = (int)$options['protocol_level'];
			if( $this->_options['protocol_name'] === 'MQTT' && $protocol_level !== 4 ){
				throw new \Exception( 'Bad protocol_level of options, expected 4 for protocol_name MQTT but ' . $options['protocol_level'] . ' provided.' );
			}
			if( $this->_options['protocol_name'] === 'MQIsdp' && $protocol_level !== 3 ){
				throw new \Exception( 'Bad protocol_level of options, expected 3 for protocol_name MQTT but ' . $options['protocol_level'] . ' provided.' );
			}
			$this->_options['protocol_level'] = $protocol_level;
		}

		if( isset( $options['client_id'] ) ){
			if( ! static::isString( $options['client_id'] ) ){
				throw new \Exception( 'Bad client_id of options, expected string or integer but ' . gettype( $options['client_id'] ) . ' provided.' );
			}
			$this->_options['client_id'] = $options['client_id'];
		} else{
			$this->_options['client_id'] = $this->createRandomClientId();
		}

		if( isset( $options['will'] ) ){
			$will = $options['will'];
			$required = array(
				'qos',
				'topic',
				'content'
			);
			foreach( $required as $key ){
				if( ! isset( $will[ $key ] ) ){
					throw new \Exception( 'Bad will options, $will[' . $key . '] missing.' );
				}
			}
			if( ! static::isString( $will['topic'] ) ){
				throw new \Exception( 'Bad $will[\'topic\'] of options, expected string or integer but ' . gettype( $will['topic'] ) . ' provided.' );
			}
			if( ! static::isString( $will['content'] ) ){
				throw new \Exception( 'Bad $will[\'content\'] of options, expected string or integer but ' . gettype( $will['content'] ) . ' provided.' );
			}
			if( $this->checkInvalidQos( $will['qos'] ) ){
				throw new \Exception( 'Bad will qos:' . var_export( $will['qos'], true ) );
			}
			$this->_options['will'] = $options['will'];
		}

		if( isset( $options['reconnect_period'] ) ){
			$reconnect_period = (int)$options['reconnect_period'];
			if( ! static::isString( $reconnect_period ) ){
				throw new \Exception( 'Bad reconnect_period of options, expected integer but ' . gettype( $options['reconnect_period'] ) . ' provided.' );
			}
			if( $reconnect_period < 0 ){
				throw new \Exception( 'Bad reconnect_period, expected integer which not less than 0 but ' . $options['reconnect_period'] . ' provided.' );
			}
			$this->_options['reconnect_period'] = $reconnect_period;
		}

		if( isset( $options['connect_timeout'] ) ){
			$connect_timeout = (int)$options['connect_timeout'];
			if( ! static::isString( $connect_timeout ) ){
				throw new \Exception( 'Bad connect_timeout of options, expected integer but ' . gettype( $options['connect_timeout'] ) . ' provided.' );
			}
			if( $connect_timeout <= 0 ){
				throw new \Exception( 'Bad connect_timeout, expected integer which greater than 0 but ' . $options['connect_timeout'] . ' provided.' );
			}
			$this->_options['connect_timeout'] = $connect_timeout;
		}

		if( isset( $options['resubscribe'] ) && ! $options['resubscribe'] ){
			$this->_options['resubscribe'] = false;
		}

		if( ! empty( $options['bindto'] ) ){
			$this->_options['bindto'] = $options['bindto'];
		}

		if( isset( $options['ssl'] ) ){
			$this->_options['ssl'] = $options['ssl'];
		}

		if( isset( $options['debug'] ) ){
			$this->_options['debug'] = ! empty( $options['debug'] );
		}
	}
}
