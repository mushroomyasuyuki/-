#!/bin/sh
# 画面・API（router / rest / view / templates）から $wpdb を直接使っていないことを確認する。
# データ操作は SS_Repo（お客様で絞り込み）か SS_System（認証・定期処理用）だけを通す。
cd "$(dirname "$0")/.." || exit 1
if grep -n '\$wpdb' includes/class-ss-router.php includes/class-ss-rest.php includes/class-ss-view.php templates/*.php; then
  echo "FAIL: direct \$wpdb use in router/rest/view/templates"; exit 1
fi
echo "PASS: no direct \$wpdb in router/rest/view/templates"
