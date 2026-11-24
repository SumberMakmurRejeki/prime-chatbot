export interface CandidateApplyFormData {
    checksheet_code: string;
    checksheet_name: string;
    description: string | null;
    is_active: string;
}

export interface CandidateApplyRequest {
    checksheet_code: string;
    checksheet_name: string;
    description: string | null;
    is_active: string;
    wizard_payload: string;
    token: string;
    confirmed: 1;
}

export declare function buildCandidateApplyRequest(
    form: CandidateApplyFormData,
    wizardPayload: string,
    token: string,
): CandidateApplyRequest;

export declare function normalizeCandidateStandards(input: unknown): Record<string, Record<string, unknown>[]>;
