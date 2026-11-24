import type { PageProps } from '@inertiajs/core';

/**
 * Identitas shell bersama. Hanya nama dan peran yang dibagikan global;
 * kapabilitas tambahan (mis. manage_ai) menyusul sebagai prop halaman karena
 * hanya dibutuhkan sebagian shell.
 */
export interface AuthProps {
    name: string | null;
    role: string | null;
}

export interface DashboardDateRange {
    start: string;
    end: string;
}

export interface MachineSummary {
    total: number;
    active: number;
    inactive: number;
}

export interface PmStatus {
    completed: number;
    overdue: number;
    total: number;
}

export interface PmRatePoint {
    month_key: string;
    month_label: string;
    target_percentage: number;
    actual_percentage: number;
}

export interface BreakdownDurationPoint {
    part_name: string;
    total_duration_hours: number;
    total_breakdown_count: number;
}

export interface ClosedBreakdownLog {
    title: string;
    machine_name: string;
    part_name: string;
    closed_date_label: string;
    status: string;
}

export interface NotificationEndpoints {
    index: string;
    readAll: string;
    readTemplate: string;
    fallback: string;
}

export interface DashboardProps extends PageProps {
    auth: AuthProps;
    manageAi: boolean;
    dateRange: DashboardDateRange;
    defaultDateRange: DashboardDateRange;
    machineSummary: MachineSummary;
    pmStatus: PmStatus;
    mtbfHours: number;
    mtbrHours: number;
    pmRateSeries: PmRatePoint[];
    breakdownDurationSeries: BreakdownDurationPoint[];
    closedBreakdownLogs: ClosedBreakdownLog[];
    loadErrorMessage: string | null;
    errors: Record<string, string>;
    notificationEndpoints: NotificationEndpoints | null;
}

export interface MachineLocation {
    id: number;
    location_name: string;
    is_active: boolean;
}

export interface MasterMachine {
    id: number;
    machine_code: string;
    machine_name: string;
    description: string | null;
    location_id: number;
    location_name: string;
    lifecycle_status: 'active' | 'inactive' | 'retired';
    created_at: string | null;
    updated_at: string | null;
    qr_image_url: string | null;
    update_url: string;
    qr_generate_url: string;
    activate_url: string | null;
    deactivate_url: string | null;
    retire_url: string | null;
    delete_url: string;
}

export interface MachineFilters {
    search: string;
    status: '' | 'active' | 'inactive' | 'retired';
    location_id: number | null;
    sort: 'machine_code' | 'machine_name';
    direction: 'asc' | 'desc';
}

export interface MasterMachineProps extends PageProps {
    auth: AuthProps;
    manageAi: boolean;
    machines: {
        data: MasterMachine[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
        links: { url: string | null; label: string; active: boolean }[];
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    locations: MachineLocation[];
    filters: MachineFilters;
    statusChoices: { value: string; label: string }[];
    storeUrl: string;
    flash: { success: string | null; error: string | null };
    errors: Record<string, string>;
    notificationEndpoints: NotificationEndpoints | null;
}

/** Kontrak props halaman Master Action; Laravel tetap otoritas validasi dan lifecycle. */
export interface MasterAction {
    id: number;
    action_label: string;
    is_active: boolean;
    update_url: string;
    activate_url: string;
    deactivate_url: string;
}

export interface MasterActionProps extends PageProps {
    auth: AuthProps;
    manageAi: boolean;
    actions: {
        data: MasterAction[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
        links: { url: string | null; label: string; active: boolean }[];
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    filters: { search: string; status: 'all' | 'active' | 'inactive' };
    storeUrl: string;
    flash: { success: string | null; error: string | null };
    errors: Record<string, string>;
    notificationEndpoints: NotificationEndpoints | null;
}

export type ProofState = 'server' | 'client';

/** Kontrak props halaman bukti SPA-03. */
export interface SpaProofProps {
    auth: AuthProps;
    proofStage: string;
    state: ProofState;
    clientVisitUrl: string;
    serverVisitUrl: string;
}

/** Kontrak props halaman kandidat create/edit Master Checksheet; server tetap otoritas. */
export interface ChecksheetCandidateMachine {
    id: number;
    code: string;
    name: string;
    location: string | null;
}

export interface ChecksheetCandidateAction {
    id: number;
    action_label: string;
    is_active: boolean;
}

export interface ChecksheetCandidate {
    id: number | null;
    checksheet_code: string;
    checksheet_name: string;
    description: string;
    is_active: boolean;
}

/** Payload wizard sesuai FormRequest; urutan kunci tidak penting untuk validasi. */
export interface WizardPayload {
    selected_machine_ids: number[];
    parts: Record<string, { id: string; name: string; description?: string }[]>;
    standards: Record<string, unknown[]>;
    schedule: {
        frequency_type: string;
        weekly_days: number[];
        monthly_day: number | null;
        operational_from: string;
        start_date?: string | null;
        generate_until?: string | null;
    };
}

export interface ChecksheetCandidateProps extends PageProps {
    scheduleDatesUrl: string;
    auth: AuthProps;
    manageAi: boolean;
    isEdit: boolean;
    checksheet: ChecksheetCandidate;
    machines: ChecksheetCandidateMachine[];
    actions: ChecksheetCandidateAction[];
    payload: WizardPayload | null;
    originalMachineIds: number[];
    originalSchedule: WizardPayload['schedule'] | null;
    storeUrl: string;
    submitUrl: string;
    showUrl: string | null;
    previewUrl: string | null;
    applyUrl: string | null;
    indexUrl: string;
    actionStoreUrl: string;
    notificationEndpoints: NotificationEndpoints;
}
