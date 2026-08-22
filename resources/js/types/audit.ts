export type AuditStatus =
    | 'queued'
    | 'preparing'
    | 'running'
    | 'finalizing'
    | 'completed'
    | 'failed'
    | 'cancelled';

export interface AuditRunSummary {
    id: string;
    auditId: string;
    type: 'audit' | 'benchmark';
    status: AuditStatus;
    target: string;
    categories: string[];
    confirmed: number;
    suspected: number;
    score: number | null;
    hasBenchmark: boolean;
    createdAt: string;
    startedAt: string | null;
    finishedAt: string | null;
    parameters?: Record<string, unknown>;
    error?: string | null;
    cancellationRequested?: boolean;
}

export interface AuditEvent {
    sequence: number;
    type: string;
    category: string | null;
    role: string | null;
    payload: Record<string, unknown>;
    artifact_ref?: string | null;
    occurred_at?: string;
}

export interface AuditArtifact {
    path: string;
    bytes: number;
}
