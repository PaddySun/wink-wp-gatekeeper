<?php
/**
 * 驱动工厂（【P1-6·A1-F3】单一构造来源——四散手写 if/elseif 工厂收敛）。
 *
 * 独立审计实锤（AUDIT-INDEP-2026-11 §4 P1-6）：驱动构造逻辑存在 4 处独立手写工厂
 * ——Bootstrap 云审链 5 分支 / Bootstrap 验证码选择 2 分支 / Bootstrap 仪表盘体检
 * 2 分支 / ExistingAuditWorker 体检工厂 5 分支——每新增一个云审驱动必须同步改 2 个
 * 工厂，漏一处=该驱动存量体检通道静默不可用。历史两犯：M5·C2（deepseek 漏体检工厂
 * ——chain=['deepseek'] 恒解析 null）、M6·H4（openai_custom 二犯同型）。
 *
 * 收敛设计（registry 已存 class 名——DriverRegistry.php 头注「装配期由 Bootstrap
 * 负责存在性检查」，本工厂即装配期构造面）：
 *   ① default_registry()：注册清单单一来源（原 Bootstrap::assemble_gates 九行注册
 *     平移至此——新驱动 = 新文件 + 本方法一行注册，全库唯一登记点）；
 *   ② build_captcha()/build_review()：按 registry class 名反射派生构造形态，手写
 *     分支消灭。两族构造协议（构造器形参形状自描述）：
 *       - 经典族： (array $settings, RemoteClient $client [, ?callable $disaster_limiter])
 *         ——TMS/Green/Baidu/腾讯验证码/阿里验证码；可选容灾限速器形参仅当构造器
 *         声明了 disaster_limiter 形参且调用方传入时透传（Bootstrap 装配位传、
 *         仪表盘体检位不传=现分支逐位照抄）。
 *       - LLM 族： (array $config, RemoteClient $client, ?callable $usage_sink,
 *         ?callable $clock, array $settings)——DeepSeek/OpenAiCustom 协议族「差异仅
 *         配置」；config 由类自派生 config_from_settings($settings)；$stats_bridge
 *         钉【S4-F3】stats 子树第 5 参两态（Bootstrap 云审链位=true 入桥；存量体检
 *         位=false 不传=批前行为逐位照抄——raw 快照桥缺省空数组）。
 *   ③ 未知 id / 未实装 class / 非两族构造形态 → null（静默剔除=现「无分支」语义；
 *     全集矩阵契约钉 tests/DriverFactoryTest.php——漏登记工厂位=红，矩阵钉永绝）。
 *
 * WP 边界纪律：本类零 WP API（反射+实例构造纯 PHP）；传输脊 RemoteClient 由调用方
 * 注入（装配位带 Usage 计量钩子、体检/仪表盘位同款或空壳——构造参数逐位照抄现分支）。
 *
 * @package WinkGatekeeper\Drivers
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Drivers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

use WinkGatekeeper\Drivers\Captcha\AliyunCaptchaDriver;
use WinkGatekeeper\Drivers\Captcha\TencentCaptchaDriver;
use WinkGatekeeper\Drivers\Review\AliyunGreenPlusDriver;
use WinkGatekeeper\Drivers\Review\BaiduTextModerationDriver;
use WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver;
use WinkGatekeeper\Drivers\Review\LocalRulesDriver;
use WinkGatekeeper\Drivers\Review\OpenAiCustomReviewDriver;
use WinkGatekeeper\Drivers\Review\TencentTmsDriver;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Ip\AbuseIpdbService;

final class DriverFactory {

	/**
	 * 驱动注册表单一来源（原 Bootstrap::assemble_gates 注册清单平移；新驱动 =
	 * 新文件 + 此处一行注册——registry 条目顺序 = Tab① 点名册渲染序，勿重排）。
	 *
	 * @return DriverRegistry
	 */
	public static function default_registry(): DriverRegistry {
		$registry = new DriverRegistry();
		$registry->register( TencentCaptchaDriver::registry_entry() );
		$registry->register( AliyunCaptchaDriver::registry_entry() ); // B3：阿里云验证码 2.0（05 §1.2）。
		$registry->register( LocalRulesDriver::registry_entry() );
		$registry->register( TencentTmsDriver::registry_entry() ); // C4：腾讯 TMS 文本审核。
		$registry->register( AliyunGreenPlusDriver::registry_entry() ); // B5：阿里文本审核增强版 PLUS（05 §2.5）。
		$registry->register( BaiduTextModerationDriver::registry_entry() ); // B6：百度文本审核（05 §2.4——token 30 天缓存复用）。
		$registry->register( DeepSeekReviewDriver::registry_entry() ); // 【M5·U4】DeepSeek LLM（05 §2.2 协议族+M5 定稿 §4 五件套）。
		$registry->register( OpenAiCustomReviewDriver::registry_entry() ); // 【M6·批E】自定义 OpenAI 兼容端点（registry 第 10 条目——纯配置子类，四件套全继承基类）。
		/* 【v1.7·M3-IP·定稿 §2 I2】abuseipdb 注册（type='ip'/billing='free_quota'/frontend=[]/
		 * supports_report=true——O5-P3-3 三字段占位）：by_type('ip') 非空后 Tab① 点名册卡/
		 * Tab⑥ IP 单列行/review_split 折减/Labels「信誉查询」自动生效（渲染侧既有逻辑零改动）。 */
		$registry->register( AbuseIpdbService::registry_entry() );
		return $registry;
	}

	/**
	 * 验证码驱动构造（registry class 名派生；is_configured 过滤留在调用方——装配位
	 * 「选中且配置齐全才装配」/仪表盘体检位「未配置=false 回落」语义分置）。
	 *
	 * @param DriverRegistry    $registry         驱动注册表（default_registry() 单一来源）。
	 * @param string            $id               驱动 id（settings captcha.driver 选中值）。
	 * @param array<string, mixed> $settings      merge 后整树。
	 * @param RemoteClient      $client           传输脊。
	 * @param callable|null     $disaster_limiter 容灾限速器（仅构造器声明 disaster_limiter
	 *                                            形参的驱动消费——Bootstrap 装配位传
	 *                                            wp_disaster_limiter；仪表盘体检位不传）。
	 * @return CaptchaDriverInterface|null null=registry miss/未实装/未知构造形态。
	 */
	public static function build_captcha( DriverRegistry $registry, string $id, array $settings, RemoteClient $client, ?callable $disaster_limiter = null ): ?CaptchaDriverInterface {
		$driver = self::construct( $registry, $id, $settings, $client, false, $disaster_limiter );
		return ( $driver instanceof CaptchaDriverInterface ) ? $driver : null;
	}

	/**
	 * 云审驱动构造（registry class 名派生；is_configured 过滤经 is_configured() 消费口
	 * 在调用方完成——云审链「未配置静默剔除」/体检工厂「首个已配置」语义分置）。
	 *
	 * @param DriverRegistry    $registry     驱动注册表（default_registry() 单一来源）。
	 * @param string            $id           驱动 id（review.cloud.chain 条目）。
	 * @param array<string, mixed> $settings   merge 后整树。
	 * @param RemoteClient      $client       传输脊。
	 * @param bool              $stats_bridge 【S4-F3】LLM 族 stats 子树第 5 参（云审链
	 *                                        位=true 入桥；存量体检位=false 不传）。
	 * @return ReviewDriverInterface|null null=registry miss/未实装/未知构造形态/local_rules
	 *                                    等非云审协议形态（恒首不入云审链）。
	 */
	public static function build_review( DriverRegistry $registry, string $id, array $settings, RemoteClient $client, bool $stats_bridge ): ?ReviewDriverInterface {
		$driver = self::construct( $registry, $id, $settings, $client, $stats_bridge, null );
		return ( $driver instanceof ReviewDriverInterface ) ? $driver : null;
	}

	/**
	 * 云审驱动配置判定（is_configured 单一消费口）。
	 *
	 * ReviewDriverInterface 接口面不声明 is_configured（LocalRules 等恒配二值驱动无
	 * 凭据面；扩接口会波及全部实现体与测试假体——本批不动接口契约）；五个云审驱动
	 * 类全数实有该方法。method_exists 窄化 = 「云审驱动恒有该判定」的显式声明位
	 * （本工厂唯一消费口——Bootstrap 云审链/ExistingAuditWorker 体检工厂共用）。
	 *
	 * @param ReviewDriverInterface $driver 云审驱动实例。
	 * @return bool
	 */
	public static function is_configured( ReviewDriverInterface $driver ): bool {
		return method_exists( $driver, 'is_configured' ) && $driver->is_configured();
	}

	/**
	 * 构造派生核（两族协议反射识别；单一构造路径——四散 if/elseif 分支的唯一替代）。
	 *
	 * @param DriverRegistry       $registry         注册表。
	 * @param string               $id               驱动 id。
	 * @param array<string, mixed> $settings         整树。
	 * @param RemoteClient         $client           传输脊。
	 * @param bool                 $stats_bridge     LLM 族 stats 桥两态。
	 * @param callable|null        $disaster_limiter 容灾限速器（经典族可选形参）。
	 * @return object|null
	 */
	private static function construct( DriverRegistry $registry, string $id, array $settings, RemoteClient $client, bool $stats_bridge, ?callable $disaster_limiter ): ?object {
		$entry = $registry->get( $id );
		if ( null === $entry || ! is_string( $entry['class'] ) || '' === $entry['class'] ) {
			return null;
		}
		$class = $entry['class'];
		// registry class 字段不做 class_exists 校验（DriverRegistry 头注——M0 注册先于
		// 实装）；工厂兜底：未实装类静默剔除（=现「无分支」语义，非 fatal）。
		if ( ! class_exists( $class ) ) {
			return null;
		}
		$ref  = new \ReflectionClass( $class );
		$ctor = $ref->getConstructor();
		if ( null === $ctor || ! $ctor->isPublic() ) {
			return null;
		}
		$params = $ctor->getParameters();
		if ( count( $params ) < 2 || ! self::is_remote_client_param( $params[1] ) ) {
			return null;
		}

		// 形态 1：LLM 协议族（array $config, RemoteClient $client, ...）——config 自派生。
		if ( 'config' === $params[0]->getName() ) {
			if ( ! method_exists( $class, 'config_from_settings' ) ) {
				return null;
			}
			$config = (array) call_user_func( array( $class, 'config_from_settings' ), $settings );
			$args   = array( $config, $client );
			/* 【S4-F3】stats 子树入桥两态：云审链位传（raw_capture/raw_max_bytes 面对齐
			 * 同族驱动）；存量体检位不传（缺省空数组=桥按默认 512 取=批前行为）。
			 * 形参位钉死第 5 参 'settings'（OpenAiCompatibleReviewDriver 形态）。 */
			if ( $stats_bridge && 5 === count( $params ) && 'settings' === $params[4]->getName() ) {
				$args = array( $config, $client, null, null, $settings );
			}
			return $ref->newInstanceArgs( $args );
		}

		// 形态 2：经典协议族（array $settings, RemoteClient $client [, ?callable $disaster_limiter]）。
		if ( 'settings' === $params[0]->getName() ) {
			$args = array( $settings, $client );
			if ( null !== $disaster_limiter
				&& count( $params ) >= 3
				&& 'disaster_limiter' === $params[2]->getName() ) {
				$args[] = $disaster_limiter;
			}
			return $ref->newInstanceArgs( $args );
		}

		return null; // 未知构造形态——静默剔除（新协议族随批在此扩形态+矩阵钉随批补位）。
	}

	/**
	 * 形参 2 类型判定：RemoteClient（两族协议共同锚——LocalRules 等第 2 参非传输脊的
	 * 驱动不入工厂构造位，恒首/独立装配语义分置）。
	 *
	 * @param \ReflectionParameter $param 第 2 构造形参。
	 * @return bool
	 */
	private static function is_remote_client_param( \ReflectionParameter $param ): bool {
		$type = $param->getType();
		return $type instanceof \ReflectionNamedType && RemoteClient::class === $type->getName();
	}
}
