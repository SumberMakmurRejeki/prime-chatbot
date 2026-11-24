export function buildCandidateApplyRequest(form, wizardPayload, token) {
    return {
        checksheet_code: form.checksheet_code,
        checksheet_name: form.checksheet_name,
        description: form.description,
        is_active: form.is_active,
        wizard_payload: wizardPayload,
        token,
        confirmed: 1,
    };
}


export function normalizeCandidateStandards(input) {
    if (!input || typeof input !== 'object' || Array.isArray(input)) return {};

    return Object.fromEntries(Object.entries(input).map(([partId, rows]) => [
        partId,
        Array.isArray(rows) ? rows.map((row) => {
            const standard = row && typeof row === 'object' && !Array.isArray(row) ? { ...row } : {};
            if (standard.input_type === 'action') {
                if (Array.isArray(standard.action_ids)) standard.action_ids = standard.action_ids.map(Number);
                standard.selection_mode = standard.selection_mode === 'multiple' ? 'multiple' : 'single';
            } else {
                delete standard.action_ids;
                delete standard.selection_mode;
            }

            return standard;
        }) : [{}],
    ]));
}
