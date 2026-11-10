<?php
/**
 * assert.php — 断言库（06 §5 工具箱；沉淀：U2 agent）。
 *
 * 用法（每条独立调用；输出 ASSERT_PASS/ASSERT_FAIL + 说明，exit 0/1）：
 *   php assert.php wk-no <字符串>                          # WK-YYYYMMDD-NNNN(M) 格式校验
 *   php assert.php eq <实际> <预期>                        # 全等
 *   php assert.php contains <文件或字符串> <子串>           # 包含（参数是存在的文件则读文件）
 *   php assert.php not-contains <文件或字符串> <子串>       # 不包含
 *   php assert.php regex <文件或字符串> <正则>              # preg_match
 *   php assert.php int-gte <实际> <最小值>                  # 整数 >=
 *   php assert.php seq <A,B,C>                             # WK 序号严格递增 +1（逗号串）
 */

declare( strict_types=1 );

array_shift( $argv );
$kind = $argv[0] ?? '';
$a    = $argv[1] ?? '';
$b    = $argv[2] ?? '';

$read = static function ( string $s ): string {
	if ( is_file( $s ) ) {
		return (string) file_get_contents( $s );
	}
	return $s;
};

$fail = static function ( string $why ) : void {
	echo "ASSERT_FAIL {$why}\n";
	exit( 1 );
};

switch ( $kind ) {
	case 'wk-no':
		if ( 1 !== preg_match( '/^WK-\d{8}-\d{4,5}$/', $a ) ) {
			$fail( "wk-no: '{$a}' 不匹配 /^WK-\\d{8}-\\d{4,5}$/" );
		}
		echo "ASSERT_PASS wk-no {$a}\n";
		break;

	case 'eq':
		if ( $a !== $b ) {
			$fail( "eq: actual=" . var_export( $a, true ) . ' expected=' . var_export( $b, true ) );
		}
		echo "ASSERT_PASS eq {$a}\n";
		break;

	case 'contains':
		if ( false === strpos( $read( $a ), $b ) ) {
			$fail( "contains: 未找到子串 [{$b}]" );
		}
		echo "ASSERT_PASS contains [{$b}]\n";
		break;

	case 'not-contains':
		if ( false !== strpos( $read( $a ), $b ) ) {
			$fail( "not-contains: 不应出现的子串存在 [{$b}]" );
		}
		echo "ASSERT_PASS not-contains [{$b}]\n";
		break;

	case 'regex':
		if ( 1 !== preg_match( $b, $read( $a ) ) ) {
			$fail( "regex: 不匹配 {$b}" );
		}
		echo "ASSERT_PASS regex {$b}\n";
		break;

	case 'int-gte':
		if ( ! is_numeric( $a ) || (int) $a < (int) $b ) {
			$fail( "int-gte: {$a} < {$b}" );
		}
		echo "ASSERT_PASS int-gte {$a} >= {$b}\n";
		break;

	case 'seq':
		$parts = array_filter( array_map( 'trim', explode( ',', $a ) ) );
		$seqs  = array();
		foreach ( $parts as $no ) {
			if ( ! preg_match( '/^WK-(\d{8})-(\d{4,5})$/', $no, $m ) ) {
				$fail( "seq: 非法编号 {$no}" );
			}
			$seqs[] = array( $m[1], (int) $m[2] );
		}
		for ( $i = 1; $i < count( $seqs ); $i++ ) {
			$prev = $seqs[ $i - 1 ];
			$cur  = $seqs[ $i ];
			$expect = $prev[1] + 1;
			if ( $cur[0] === $prev[0] && $cur[1] !== $expect ) {
				$fail( "seq: {$parts[$i]} 序号 {$cur[1]}，期望 {$expect}（前 {$parts[$i-1]}）" );
			}
		}
		echo 'ASSERT_PASS seq ' . implode( ',', $parts ) . "\n";
		break;

	default:
		fwrite( STDERR, "usage: php assert.php wk-no|eq|contains|not-contains|regex|int-gte|seq ...\n" );
		exit( 2 );
}
