<?php
/**
 * 信任存根 TrustStub（02 §3.3a/R2 §2.5——信用免验信任凭证，与 §3.3 Stub 同构不同物）。
 *
 * 它证明什么：仅证明「IP 信誉 + 历史信用」在 TTL 内对（这个 IP + 这个表单 + 这个身份）
 * 通过过。不证明内容——四闸本来就提交时必跑（02 §3.1 步骤 2.5），故凭证不绑定内容哈希
 * （绑了反而制造「预检后改一个错别字→凭证失效→重出码」的真人伤害，零安全收益）。
 *
 * 格式（复用 wp_hash('nonce') 摘要通道）：
 *   trust = base64( json{ gate:'comment', ctx, id_hash, ip_hash, iat } ) . '.' . wp_hash(payload,'nonce')
 *   id_hash = HMAC-SHA16( 游客: lower(email)|昵称 ；登录评论者: 'u:'+user_id )
 *   ip_hash = HMAC-SHA16( IpResolver::client_ip() )——与事件表 ip_hash 同盐同源（wp_salt('auth')）
 *   ctx     = comment_post_ID（与签名存根 context_id 同源）
 *
 * 六项校验（02 §3.3a 校验表；全部通过才算命中，任一失败=视同无凭证走 VerifyCache/验票，
 * 不报错不拦——正常用户有 10min 前端过期回退不会撞上）：
 *   ① 签名 hash_equals + 结构完整；② gate/ctx 与本次提交一致；③ now-iat ≤ ttl_minutes
 *   （默认 10，与 verify_cache_minutes 对齐——同一信任级别窗口同宽）；④ ip_hash 一致（换网络即失效）；
 *   ⑤ id_hash 一致（堵「预检用可信邮箱、提交换新邮箱」的偷梁换柱）；⑥（M3）提交期 ip 阶段
 *   新鲜判定 review/reject → 撤销免验（新鲜判定压过凭证——闸编排消费，不在本类）。
 *
 * 一次性 vs TTL 内可重用：TTL 内可重用（默认）——无状态签名凭证零存储零写放大；
 * 重放面被三重绑定收窄（同 IP+同表单+同身份，10 分钟窗）。wink_gatekeeper_trust_single_use
 * filter 备选一次性实现（transient wk_tr_consumed_*）不进默认。
 *
 * 分层：payload 构造与校验为纯函数（本类）；wp_hash / wp_salt 经可注入回调（闸边界注入，
 * 测试注入固定函数——与 Intercept\Stub 同模式）。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

final class TrustStub {

	/**
	 * 默认有效期（分钟；04 §2 captcha.trust.ttl_minutes——与 verify_cache_minutes 对齐）。
	 */
	public const DEFAULT_TTL_MINUTES = 10;

	/**
	 * 签发信任凭证（预检端点三判定全过时调用，03 §2.1 端点契约）。
	 *
	 * @param string   $context_id 表单会话身份（评论闸 = comment_post_ID，与 Stub context_id 同源）。
	 * @param string   $id_hash    信用身份哈希（guest_identity_input / user_identity_input 经 mac）。
	 * @param string   $ip_hash    提交者 IP 哈希（IpResolver 解析值经 mac——与事件表 ip_hash 同盐同源）。
	 * @param int      $now        签发时间戳（iat）。
	 * @param callable $hash       摘要回调 fn(string $payload): string（生产 = wp_hash($data,'nonce') 封装）。
	 * @return string
	 */
	public static function issue( string $context_id, string $id_hash, string $ip_hash, int $now, callable $hash ): string {
		$payload = self::encode_payload( $context_id, $id_hash, $ip_hash, $now );
		return $payload . '.' . call_user_func( $hash, $payload );
	}

	/**
	 * 六项校验（提交期 captcha 阶段第一步，02 §3.1 步骤 3a）。
	 *
	 * @param string   $trust      提交的凭证字符串（表单域 wk_trust）。
	 * @param string   $context_id 本次提交的 context_id。
	 * @param string   $id_hash    本次提交身份哈希（游客=lower(email)|昵称；登录='u:'+user_id）。
	 * @param string   $ip_hash    本次解析 IP 哈希。
	 * @param int      $ttl_minutes 有效期（分钟）。
	 * @param int      $now        校验时间戳。
	 * @param callable $hash       摘要回调（须与签发一致）。
	 * @return bool 全部通过=true；任一失败=false（视同无凭证，不报错不拦）。
	 */
	public static function verify( string $trust, string $context_id, string $id_hash, string $ip_hash, int $ttl_minutes, int $now, callable $hash ): bool {
		$ttl_minutes = max( 0, $ttl_minutes );

		$dot = strrpos( $trust, '.' );
		if ( false === $dot || 0 === $dot || strlen( $trust ) - 1 === $dot ) {
			return false; // ① 缺 payload 或缺哈希段（结构不完整）。
		}

		$payload = substr( $trust, 0, $dot );
		$mac     = substr( $trust, $dot + 1 );

		if ( ! hash_equals( call_user_func( $hash, $payload ), $mac ) ) {
			return false; // ① 签名不符/篡改（时序安全比较）。
		}

		$decoded = base64_decode( $payload, true );
		if ( false === $decoded ) {
			return false; // ① 结构不完整。
		}
		$data = json_decode( $decoded, true );
		if ( ! is_array( $data ) ) {
			return false; // ① 同上。
		}
		foreach ( array( 'gate', 'ctx', 'id_hash', 'ip_hash', 'iat' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				return false; // ① 结构不完整。
			}
		}

		// 严格类型校验后逐项比对（② gate/ctx；③ TTL；④ ip；⑤ id——全字符串/整型全等）。
		if ( ! is_string( $data['gate'] ) || ! is_string( $data['ctx'] )
			|| ! is_string( $data['id_hash'] ) || ! is_string( $data['ip_hash'] )
			|| ! is_int( $data['iat'] ) ) {
			return false; // ① 类型异常视同结构不完整。
		}
		if ( 'comment' !== $data['gate'] || $data['ctx'] !== $context_id ) {
			return false; // ② gate/context_id 与本次提交不一致（跨表单混用）。
		}
		if ( $now - $data['iat'] > $ttl_minutes * 60 ) {
			return false; // ③ 超出 TTL（≤ ttl 通过——边界值含）。
		}
		if ( $data['iat'] - $now > 0 ) {
			return false; // ③ 时钟倒挂（iat 晚于校验时刻）按未生效处理。
		}
		if ( ! hash_equals( $data['ip_hash'], $ip_hash ) ) {
			return false; // ④ 换网络/换出口即失效（重预检即可）。
		}
		if ( ! hash_equals( $data['id_hash'], $id_hash ) ) {
			return false; // ⑤ 身份不一致（堵偷梁换柱；代价=改昵称错字需重预检——文案给台阶）。
		}

		return true;
	}

	/**
	 * 游客信用身份输入（02 §3.3a 格式注：lower(email)|昵称）。
	 *
	 * @param string $email  邮箱（归一小写——与 WP 写入/计数语义一致）。
	 * @param string $author 昵称。
	 * @return string
	 */
	public static function guest_identity_input( string $email, string $author ): string {
		return strtolower( trim( $email ) ) . '|' . $author;
	}

	/**
	 * 登录评论者信用身份输入（'u:'+user_id——用户字面维度）。
	 *
	 * @param int $user_id 用户 ID。
	 * @return string
	 */
	public static function user_identity_input( int $user_id ): string {
		return 'u:' . $user_id;
	}

	/**
	 * payload 构造（纯函数，独立可见以便复用与测试）。
	 *
	 * @param string $context_id 表单会话身份。
	 * @param string $id_hash    信用身份哈希。
	 * @param string $ip_hash    IP 哈希。
	 * @param int    $now        签发时间戳。
	 * @return string base64 编码的 json payload。
	 */
	public static function encode_payload( string $context_id, string $id_hash, string $ip_hash, int $now ): string {
		return base64_encode(
			(string) json_encode(
				array(
					'gate'    => 'comment',
					'ctx'     => $context_id,
					'id_hash' => $id_hash,
					'ip_hash' => $ip_hash,
					'iat'     => $now,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
	}
}
