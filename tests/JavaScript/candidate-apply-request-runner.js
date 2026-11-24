import { stdin, stdout } from 'node:process';
import { buildCandidateApplyRequest } from '../../resources/js/pages/Master/Checksheet/candidate-apply-request.js';

let input = '';

for await (const chunk of stdin) {
    input += chunk;
}

const { form, wizardPayload, token } = JSON.parse(input);
stdout.write(JSON.stringify(buildCandidateApplyRequest(form, wizardPayload, token)));
