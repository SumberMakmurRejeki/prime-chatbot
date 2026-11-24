import assert from 'node:assert/strict';
import test from 'node:test';

import { buildCandidateApplyRequest } from '../../resources/js/pages/Master/Checksheet/candidate-apply-request.js';

test('candidate Apply request includes the master fields required by its update request', () => {
    const form = {
        checksheet_code: 'PM-CANDIDATE-01',
        checksheet_name: 'Candidate checksheet',
        description: 'Candidate description',
        is_active: '0',
    };
    const wizardPayload = '{"selected_machine_ids":[12],"schedule":{"frequency_type":"daily"}}';
    const token = 'a'.repeat(64);

    assert.deepEqual(buildCandidateApplyRequest(form, wizardPayload, token), {
        checksheet_code: 'PM-CANDIDATE-01',
        checksheet_name: 'Candidate checksheet',
        description: 'Candidate description',
        is_active: '0',
        wizard_payload: wizardPayload,
        token,
        confirmed: 1,
    });
});


test('malformed standard row maps normalize without throwing', async () => {
    const { normalizeCandidateStandards } = await import('../../resources/js/pages/Master/Checksheet/candidate-apply-request.js');

    assert.deepEqual(normalizeCandidateStandards({
        'part-1': { unexpected: 'shape' },
        'part-2': [null, 12, 'invalid', { input_type: 'action', action_ids: ['4'], selection_mode: 'multiple' }],
    }), {
        'part-1': [{}],
        'part-2': [{}, {}, {}, { input_type: 'action', action_ids: [4], selection_mode: 'multiple' }],
    });
});
