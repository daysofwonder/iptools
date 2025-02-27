<?php
namespace IPTools;

use IPTools\Exception\RangeException;
use ReturnTypeWillChange;

/**
 * @author Joe Huss <detain@interserver.net>
 * @author Safarov Alisher <alisher.safarov@outlook.com>
 * @link https://github.com/detain/iptools
 */
class Range implements \Iterator, \Countable
{
	use PropertyTrait;

	/**
	 * @var IP
	 */
	private $firstIP;
	/**
	 * @var IP
	 */
	private $lastIP;
	/**
	 * @var int
	 */
	private $position = 0;

	/**
	 * @param IP $firstIP
	 * @param IP $lastIP
	 * @throws RangeException
	 */
	public function __construct(IP $firstIP, IP $lastIP)
	{
		$this->setFirstIP($firstIP);
		$this->setLastIP($lastIP);
	}

	/**
	 * @param string $data
	 * @return Range
	 */
	public static function parse($data)
	{
		if (strpos($data,'/') || strpos($data,' ')) {
			$network = Network::parse($data);
			$firstIP = $network->getFirstIP();
			$lastIP  = $network->getLastIP();
		} elseif (strpos($data, '*') !== false) {
			$firstIP = IP::parse(str_replace('*', '0', $data));
			$lastIP  = IP::parse(str_replace('*', '255', $data));
		} elseif (strpos($data, '-')) {
			list($first, $last) = explode('-', $data, 2);
			$firstIP = IP::parse($first);
			$lastIP  = IP::parse($last);
		} else {
			$firstIP = IP::parse($data);
			$lastIP  = clone $firstIP;
		}

		return new self($firstIP, $lastIP);
	}

	/**
	 * @param IP|Network|Range $find
	 * @return bool
	 * @throws RangeException
	 */
	public function contains($find)
	{
		if ($find instanceof IP) {
			/**
			* @var IP $find
			*/
			$within = (strcmp($find->inAddr(), $this->firstIP->inAddr()) >= 0)
				&& (strcmp($find->inAddr(), $this->lastIP->inAddr()) <= 0);
		} elseif ($find instanceof Range || $find instanceof Network) {
			/**
			 * @var Network|Range $find
			 */
			$within = (strcmp($find->getFirstIP()->inAddr(), $this->firstIP->inAddr()) >= 0)
				&& (strcmp($find->getLastIP()->inAddr(), $this->lastIP->inAddr()) <= 0);
		} else {
			throw new RangeException('Invalid type');
		}

		return $within;
	}

	/**
	 * Same as contains but works on an array of IP|Network|Range and returns true if any of the array items are in the range 
	 * 
	 * @param array $findArray
	 * @return bool
	 * @throws \Exception
	 */
	public function containsAny($findArray)
	{
		foreach ($findArray as $find) {
			if ($find instanceof IP) {
				/**
				* @var IP $find
				*/
				$within = (strcmp($find->inAddr(), $this->firstIP->inAddr()) >= 0)
					&& (strcmp($find->inAddr(), $this->lastIP->inAddr()) <= 0);
			} elseif ($find instanceof Range || $find instanceof Network) {
				/**
				 * @var Network|Range $find
				 */
				$within = (strcmp($find->getFirstIP()->inAddr(), $this->firstIP->inAddr()) >= 0)
					&& (strcmp($find->getLastIP()->inAddr(), $this->lastIP->inAddr()) <= 0);
			}
			if ($within) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Same as contains but works on an array of IP|Network|Range and returns true if all of the array items are in the range 
	 * 
	 * @param array $findArray
	 * @return bool
	 * @throws \Exception
	 */
	public function containsAll($findArray)
	{
		foreach ($findArray as $find) {
			if ($find instanceof IP) {
				/**
				* @var IP $find
				*/
				$within = (strcmp($find->inAddr(), $this->firstIP->inAddr()) >= 0)
					&& (strcmp($find->inAddr(), $this->lastIP->inAddr()) <= 0);
			} elseif ($find instanceof Range || $find instanceof Network) {
				/**
				 * @var Network|Range $find
				 */
				$within = (strcmp($find->getFirstIP()->inAddr(), $this->firstIP->inAddr()) >= 0)
					&& (strcmp($find->getLastIP()->inAddr(), $this->lastIP->inAddr()) <= 0);
			}
			if (!$within) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param IP $ip
	 * @throws RangeException
	 */
	public function setFirstIP(IP $ip)
	{
		if ($this->lastIP && strcmp($ip->inAddr(), $this->lastIP->inAddr()) > 0) {
			throw new RangeException('First IP is grater than second');
		}

		$this->firstIP = $ip;
	}

	/**
	 * @param IP $ip
	 * @throws RangeException
	 */
	public function setLastIP(IP $ip)
	{
		if ($this->firstIP && strcmp($ip->inAddr(), $this->firstIP->inAddr()) < 0) {
			throw new RangeException('Last IP is less than first');
		}

		$this->lastIP = $ip;
	}

	/**
	 * @return IP
	 */
	public function getFirstIP()
	{
		return $this->firstIP;
	}

	/**
	 * @return IP
	 */
	public function getLastIP()
	{
		return $this->lastIP;
	}

	/**
	 * @return Network[]
	 */
	public function getNetworks()
	{
		$span = $this->getSpanNetwork();

		$networks = array();

		if ($span->getFirstIP()->inAddr() === $this->firstIP->inAddr()
			&& $span->getLastIP()->inAddr() === $this->lastIP->inAddr()
		) {
			$networks = array($span);
		} else {
			if ($span->getFirstIP()->inAddr() !== $this->firstIP->inAddr()) {
				$excluded = $span->exclude($this->firstIP->prev());
				foreach ($excluded as $network) {
					if (strcmp($network->getFirstIP()->inAddr(), $this->firstIP->inAddr()) >= 0) {
						$networks[] = $network;
					}
				}
			}

			if ($span->getLastIP()->inAddr() !== $this->lastIP->inAddr()) {
				if (!$networks) {
					$excluded = $span->exclude($this->lastIP->next());
				} else {
					$excluded = array_pop($networks);
					$excluded = $excluded->exclude($this->lastIP->next());
				}

				foreach ($excluded as $network) {
					$networks[] = $network;
					if ($network->getLastIP()->inAddr() === $this->lastIP->inAddr()) {
						break;
					}
				}
			}

		}

		return $networks;
	}

	/**
	 * @return Network
	 */
	public function getSpanNetwork()
	{
		$xorIP = IP::parseInAddr($this->getFirstIP()->inAddr() ^ $this->getLastIP()->inAddr());

		preg_match('/^(0*)/', $xorIP->toBin(), $match);

		$prefixLength = strlen($match[1]);

		$ip = IP::parseBin(str_pad(substr($this->getFirstIP()->toBin(), 0, $prefixLength), $xorIP->getMaxPrefixLength(), '0'));

		return new Network($ip, Network::prefix2netmask($prefixLength, $ip->getVersion()));
	}

	/**
	 * @return IP
	 */
	#[ReturnTypeWillChange]
	public function current()
	{
		return $this->firstIP->next($this->position);
	}

	/**
	 * @return int
	 */
	#[ReturnTypeWillChange]
	public function key()
	{
		return $this->position;
	}

    /**
     * @return void
     */
	#[ReturnTypeWillChange]
	public function next()
	{
		++$this->position;
	}

    /**
     * @return void
     */
	#[ReturnTypeWillChange]
	public function rewind()
	{
		$this->position = 0;
	}

	/**
	 * @return bool
	 */
	#[ReturnTypeWillChange]
	public function valid()
	{
		return strcmp($this->firstIP->next($this->position)->inAddr(), $this->lastIP->inAddr()) <= 0;
	}

	/**
	 * @return int
	 */
	#[ReturnTypeWillChange]
	public function count()
	{
		return (integer)bcadd(bcsub($this->lastIP->toLong(), $this->firstIP->toLong()), 1);
	}
}
