<?php
/**
 * AbuseIPDB report 类目码表（M3-IP 定稿 §4.5 官方固化——2026-11-12 统筹在线核验，
 * L0 缺口补上；05 §3.1「类目码表」行同源）。
 *
 * 纯常量+人话名（Tab④ 上报分区下拉 I4 消费；I3 服务面消费=每触发面默认类目与
 * 每面覆盖键 `iprep.report.category_<trigger>` 的合法性校验——0=用默认类目）。
 *
 * 官方 Description 校准（拍板 0.8）：18 Brute-Force 含 webpage logins（T1/T3 默认）、
 * 10 Web Spam 含 CMS spam（T2/T4 默认）、12 Blog Spam 可作下拉备选。
 *
 * @package WinkGatekeeper\Ip
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Ip;

final class AbuseIpdbCategories {

	/** 1 DNS Compromise. */
	public const DNS_COMPROMISE = 1;
	/** 2 DNS Poisoning. */
	public const DNS_POISONING = 2;
	/** 3 Fraud Orders. */
	public const FRAUD_ORDERS = 3;
	/** 4 DDoS Attack. */
	public const DDOS_ATTACK = 4;
	/** 5 FTP Brute-Force. */
	public const FTP_BRUTE_FORCE = 5;
	/** 6 Ping of Death. */
	public const PING_OF_DEATH = 6;
	/** 7 Phishing. */
	public const PHISHING = 7;
	/** 8 Fraud VoIP. */
	public const FRAUD_VOIP = 8;
	/** 9 Open Proxy. */
	public const OPEN_PROXY = 9;
	/** 10 Web Spam（T2/T4 默认——官方 Description 含 CMS spam）. */
	public const WEB_SPAM = 10;
	/** 11 Email Spam. */
	public const EMAIL_SPAM = 11;
	/** 12 Blog Spam（下拉备选）. */
	public const BLOG_SPAM = 12;
	/** 13 VPN IP. */
	public const VPN_IP = 13;
	/** 14 Port Scan. */
	public const PORT_SCAN = 14;
	/** 15 Hacking. */
	public const HACKING = 15;
	/** 16 SQL Injection. */
	public const SQL_INJECTION = 16;
	/** 17 Spoofing. */
	public const SPOOFING = 17;
	/** 18 Brute-Force（T1/T3 默认——官方 Description 含 webpage logins）. */
	public const BRUTE_FORCE = 18;
	/** 19 Bad Web Bot. */
	public const BAD_WEB_BOT = 19;
	/** 20 Exploited Host. */
	public const EXPLOITED_HOST = 20;
	/** 21 Web App Attack. */
	public const WEB_APP_ATTACK = 21;
	/** 22 SSH. */
	public const SSH = 22;
	/** 23 IoT Targeted. */
	public const IOT_TARGETED = 23;

	/**
	 * 全量码表（id => 人话名；官方固化 1–23）。
	 *
	 * @var array<int, string>
	 */
	public const MAP = array(
		self::DNS_COMPROMISE  => 'DNS Compromise',
		self::DNS_POISONING   => 'DNS Poisoning',
		self::FRAUD_ORDERS    => 'Fraud Orders',
		self::DDOS_ATTACK     => 'DDoS Attack',
		self::FTP_BRUTE_FORCE => 'FTP Brute-Force',
		self::PING_OF_DEATH   => 'Ping of Death',
		self::PHISHING        => 'Phishing',
		self::FRAUD_VOIP      => 'Fraud VoIP',
		self::OPEN_PROXY      => 'Open Proxy',
		self::WEB_SPAM        => 'Web Spam',
		self::EMAIL_SPAM      => 'Email Spam',
		self::BLOG_SPAM       => 'Blog Spam',
		self::VPN_IP          => 'VPN IP',
		self::PORT_SCAN       => 'Port Scan',
		self::HACKING         => 'Hacking',
		self::SQL_INJECTION   => 'SQL Injection',
		self::SPOOFING        => 'Spoofing',
		self::BRUTE_FORCE     => 'Brute-Force',
		self::BAD_WEB_BOT     => 'Bad Web Bot',
		self::EXPLOITED_HOST  => 'Exploited Host',
		self::WEB_APP_ATTACK  => 'Web App Attack',
		self::SSH             => 'SSH',
		self::IOT_TARGETED    => 'IoT Targeted',
	);

	/**
	 * 每触发面默认类目（定稿 §4.2 触发集表——categories 必填至少一个 ID 的保底位）。
	 *
	 * @var array<string, int>
	 */
	private const TRIGGER_DEFAULTS = array(
		'captcha_failed'    => self::BRUTE_FORCE, // T1 有票核验失败（伪造/重放票）→ 18。
		'review_reject'     => self::WEB_SPAM,    // T2 云审 reject 累计 → 10。
		'login_attempts'    => self::BRUTE_FORCE, // T3 登录 IP 聚合饱和 → 18。
		'local_rules_fail'  => self::WEB_SPAM,    // T4 本地四规则闸累计 → 10。
	);

	/**
	 * 类目码是否在官方表内（1–23；覆盖键 0=用默认类目不在此判）。
	 *
	 * @param int $id 类目码。
	 * @return bool
	 */
	public static function is_valid( int $id ): bool {
		return isset( self::MAP[ $id ] );
	}

	/**
	 * 类目人话名（表外码空串——Tab④ 下拉 I4 消费）。
	 *
	 * @param int $id 类目码。
	 * @return string
	 */
	public static function label( int $id ): string {
		return self::MAP[ $id ] ?? '';
	}

	/**
	 * 触发面默认类目（未知触发面回落 T1 的 18——恒有合法 ID 保底）。
	 *
	 * @param string $trigger 触发面键。
	 * @return int
	 */
	public static function default_for( string $trigger ): int {
		return self::TRIGGER_DEFAULTS[ $trigger ] ?? self::BRUTE_FORCE;
	}
}
