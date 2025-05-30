<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that Moodle will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_modreportproblem\external;

use core_external\external_api;
use core_external\external_value;
use core_external\external_single_structure;
use core_external\external_function_parameters;
use local_modreportproblem\form\answer;
use local_modreportproblem\form\report;
use local_modreportproblem\notification\problemanswered;

/**
 * Section external api class.
 *
 * @package     local_modreportproblem
 * @copyright   2023 Willian Mano {@link https://conecti.me}
 * @author      Willian Mano <willianmanoaraujo@gmail.com>
 */
class problem extends external_api {
    /**
     * Create comment parameters
     *
     * @return external_function_parameters
     */
    public static function create_parameters() {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'The context id for the course module'),
            'jsonformdata' => new external_value(PARAM_RAW, 'The data from the form'),
        ]);
    }

    public static function create($contextid, $jsonformdata) {
        global $DB, $USER;

        $params = self::validate_parameters(self::create_parameters(),
            ['contextid' => $contextid, 'jsonformdata' => $jsonformdata]);

        $context = \core\context::instance_by_id($contextid, MUST_EXIST);

        self::validate_context($context);

        $serialiseddata = json_decode($params['jsonformdata']);

        $data = [];
        parse_str($serialiseddata, $data);

        $mform = new report($data);

        $validateddata = $mform->get_data();

        if (!$validateddata) {
            throw new \moodle_exception('invalidformdata');
        }

        $recorddata = new \stdClass();
        $recorddata->courseid = $validateddata->courseid;
        $recorddata->userid = $USER->id;
        $recorddata->cmid = $validateddata->cmid;
        $recorddata->module = $validateddata->module;
        $recorddata->type = $validateddata->type;
        $recorddata->details = $validateddata->details;
        $recorddata->timecreated = time();

        // Salva o reporte e obtém o ID.
        $problemid = $DB->insert_record('modreportproblem', $recorddata);

        // Recupera o registro completo recém-salvo.
        $problem = $DB->get_record('modreportproblem', ['id' => $problemid]);

        // Envia o e-mail para suporteti@lingopass.com.br
        self::send_report_email($problem);

        return ['status' => get_string('reportsuccess', 'local_modreportproblem')];
    }

    public static function create_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'The transaction status'),
        ]);
    }

    public static function answer_parameters() {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'The context id for the course module'),
            'jsonformdata' => new external_value(PARAM_RAW, 'The data from the form'),
        ]);
    }

    public static function answer($contextid, $jsonformdata) {
        global $DB, $USER;

        $params = self::validate_parameters(self::answer_parameters(),
            ['contextid' => $contextid, 'jsonformdata' => $jsonformdata]);

        $context = \core\context::instance_by_id($contextid, MUST_EXIST);

        self::validate_context($context);

        $serialiseddata = json_decode($params['jsonformdata']);

        $data = [];
        parse_str($serialiseddata, $data);

        $mform = new answer($data);

        $validateddata = $mform->get_data();

        if (!$validateddata) {
            throw new \moodle_exception('invalidformdata');
        }

        $problem = $DB->get_record('modreportproblem', ['id' => $validateddata->id], '*', MUST_EXIST);

        $problem->answer = $validateddata->answer;
        $problem->timeanswered = time();
        $problem->useranswer = $USER->id;

        $DB->update_record('modreportproblem', $problem);

        $notification = new problemanswered($context, $problem);
        $notification->send();

        return ['status' => get_string('answersuccess', 'local_modreportproblem')];
    }

    public static function answer_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'The transaction status'),
        ]);
    }

    /**
     * Sends a notification email to support when a new problem is reported.
     *
     * @param \stdClass $problem The problem record
     * @return void
     */
    private static function send_report_email($problem) {
        global $DB, $USER, $CFG;

        // Get user details.
        $user = $DB->get_record('user', ['id' => $problem->userid], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $problem->courseid], '*', MUST_EXIST);

        $supportemail = 'suporteti@lingopass.com.br';

        $subject = "Novo reporte de problema no Moodle (Curso: {$course->fullname})";
        $messagehtml = "
            <p>Um novo problema foi reportado no Moodle.</p>
            <ul>
                <li><b>Usuário:</b> {$user->firstname} {$user->lastname} ({$user->email})</li>
                <li><b>Curso:</b> {$course->fullname}</li>
                <li><b>Módulo:</b> {$problem->module}</li>
                <li><b>Tipo:</b> {$problem->type}</li>
                <li><b>Detalhes:</b> {$problem->details}</li>
                <li><b>Data/Hora:</b> " . userdate($problem->timecreated) . "</li>
            </ul>
        ";

        $messagetext = "Um novo problema foi reportado no Moodle.

Usuário: {$user->firstname} {$user->lastname} ({$user->email})
Curso: {$course->fullname}
Módulo: {$problem->module}
Tipo: {$problem->type}
Detalhes: {$problem->details}
Data/Hora: " . userdate($problem->timecreated);

        // Usa o usuário logado como remetente.
        email_to_user(
            (object)[
                'id' => -99,
                'email' => $supportemail,
                'firstname' => 'Suporte',
                'lastname' => 'TI'
            ],
            $user,
            $subject,
            $messagetext,
            $messagehtml
        );
    }
}
