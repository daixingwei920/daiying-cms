<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

final class MockAiReviewClient implements AiReviewClientInterface
{
    public function review(array $payload): array
    {
        $findings = is_array($payload['scan_findings'] ?? null) ? $payload['scan_findings'] : [];
        $reviewType = (string) ($payload['review_type'] ?? 'chatgpt_rules');
        $highRisk = false;
        $violations = [];
        $warnings = [];
        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $severity = strtolower((string) ($finding['severity'] ?? ''));
            $message = (string) ($finding['message'] ?? ($finding['code'] ?? 'scan finding'));
            if (in_array($severity, ['high', 'critical'], true)) {
                $highRisk = true;
                $violations[] = $message;
            } elseif ($message !== '') {
                $warnings[] = $message;
            }
        }

        $risk = $highRisk ? 'high' : ($findings === [] ? 'low' : 'medium');
        $decision = $highRisk ? 'NEEDS_SECURITY_REVIEW' : ($findings === [] ? 'NEEDS_MANUAL_REVIEW' : 'NEEDS_FIX');
        if ($reviewType === 'codex_code' && $highRisk) {
            $decision = 'NEEDS_SECURITY_REVIEW';
        }

        return [
            'decision_suggestion' => $decision,
            'risk_level' => $risk,
            'violations' => $violations,
            'warnings' => $warnings,
            'manual_review_focus' => $findings === [] ? ['确认 Manifest、权限声明、外联说明与市场文案一致'] : ['复核扫描 findings 对应文件和权限声明'],
            'required_fixes' => $highRisk ? ['修复高风险扫描项后重新提交版本'] : [],
            'confidence' => $findings === [] ? 0.74 : 0.82,
            'summary' => 'Mock AI review completed with evidence-only recommendation. Human approval is still required.',
        ];
    }
}
