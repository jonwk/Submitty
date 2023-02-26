<?php

namespace app\controllers;

namespace app\controllers;

use app\controllers\student\SubmissionController;
use app\exceptions\IOException;
use app\libraries\FileUtils;
use app\libraries\Utils;

use app\libraries\response\RedirectResponse;
use app\models\gradeable\Component;
use app\models\gradeable\Gradeable;
use app\models\gradeable\GradeableUtils;
use app\models\Course;
use app\models\User;
use app\libraries\Core;
use app\libraries\response\MultiResponse;
use app\libraries\response\WebResponse;
use app\libraries\response\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class HomePageController
 *
 * Controller to deal with the submitty home page. Once the user has been authenticated, but before they have
 * selected which course they want to access, they are forwarded to the home page.
 */
class HomePageController extends AbstractController
{

    /**
     * HomePageController constructor.
     *
     * @param Core $core
     */
    public function __construct(Core $core)
    {
        parent::__construct($core);
    }


    // /**
    //  * Creates a file with the given contents to be used to upload for a specified part.
    //  *
    //  * @param string $filename
    //  * @param string $content
    //  * @param int    $part
    //  */
    // private function addUploadFile($filename, $content = "", $part = 1)
    // {
    //     $this->config['tmp_path'] = FileUtils::joinPaths(sys_get_temp_dir(), Utils::generateRandomString());

    //     FileUtils::createDir(FileUtils::joinPaths($this->config['tmp_path'], 'files', 'part' . $part), true, 0777);
    //     $filepath = FileUtils::joinPaths($this->config['tmp_path'], 'files', 'part' . $part, $filename);

    //     if (file_put_contents($filepath, $content) === false) {
    //         throw new IOException("Could not write file to {$filepath}");
    //     }
    //     $_FILES["files{$part}"]['name'][] = $filename;
    //     $_FILES["files{$part}"]['type'][] = mime_content_type($filepath);
    //     $_FILES["files{$part}"]['size'][] = filesize($filepath);
    //     $_FILES["files{$part}"]['tmp_name'][] = $filepath;
    //     $_FILES["files{$part}"]['error'][] = null;
    // }


    private $user_id_to_User_cache = [];


    /**
     * Submit a submission to a gradeable
     * @Route("/api/getFile/{_semester}/{_course}/gradeable/{gradeable_id}/submissions", methods={"GET"}))
     * @param string $gradeable_id
     * @param string|null $user_id
     * @return MultiResponse
     */
    public function getFile($gradeable_id = null, $user_id = null, int $display_version = 0)
    {
        // http://localhost:1511/s23/sample/gradeable/grading_homework/grading/details
        $user = $this->core->getUser();

        // print("gradeable_id: ". $gradeable_id);

        if (is_null($user_id) || $user->getAccessLevel() !== User::LEVEL_SUPERUSER) {
            $user_id = $user->getId();
        }

        $user_id = "bitdiddle";
        // print("user_id: ". $user_id);

        // $user_id = 'aphacker';
        // $gradeable_id = 'grading_homework';
        // $display_version = 1;

        if (!is_null($gradeable_id) && !is_null($user_id)) {
            $gradeable = $this->tryGetGradeable($gradeable_id, false);
            if ($gradeable === false) {
                return false;
            }
            $graded_gradeable =  $this->tryGetGradedGradeable($gradeable, $user_id, false);
            if ($graded_gradeable === false) {
                return false;
            }
        }

        $add_files = function (&$files, $new_files, $start_dir_name, $graded_gradeable) {
            $files[$start_dir_name] = [];
            $hidden_files = $graded_gradeable->getGradeable()->getHiddenFiles();
            if ($new_files) {
                foreach ($new_files as $file) {
                    $skipping = false;
                    foreach (explode(",", $hidden_files) as $file_regex) {
                        $file_regex = trim($file_regex);
                        if (fnmatch($file_regex, $file["name"]) && $this->core->getUser()->getGroup() > 3) {
                            $skipping = true;
                        }
                    }
                    if (!$skipping) {
                        if ($start_dir_name == "submissions") {
                            $file["path"] = $this->setAnonPath($file["path"], $graded_gradeable->getGradeableId());
                        }
                        $path = explode('/', $file['relative_name']);
                        array_pop($path);
                        $working_dir = &$files[$start_dir_name];
                        foreach ($path as $dir) {
                            /** @var array $working_dir */
                            if (!isset($working_dir[$dir])) {
                                $working_dir[$dir] = [];
                            }
                            $working_dir = &$working_dir[$dir];
                        }
                        $working_dir[$file['name']] = $file['path'];
                    }
                }
            }
        };

        $submissions = [];
        $results = [];
        $results_public = [];
        $checkout = [];

        // NOTE TO FUTURE DEVS: There is code around line 830 (ctrl-f openAll) which depends on these names,
        // if you change here, then change there as well
        // order of these statements matter I believe
        // $graded_gradeable = $this->tryGetGradedGradeable($gradeable_id, $user_id, false);
        // print("graded_gradeable: ". $graded_gradeable);

        $display_version = $graded_gradeable->getAutoGradedGradeable()->getActiveVersion();
        // print("display_version: " . $display_version);
        $display_version_instance = $graded_gradeable->getAutoGradedGradeable()->getAutoGradedVersionInstance($display_version);
        $isVcs = $graded_gradeable->getGradeable()->isVcs();
        if ($display_version_instance !==  null) {
            $meta_files = $display_version_instance->getMetaFiles();
            $files = $display_version_instance->getFiles();

            $add_files($submissions, array_merge($meta_files['submissions'], $files['submissions']), 'submissions', $graded_gradeable);
            $add_files($checkout, array_merge($meta_files['checkout'], $files['checkout']), 'checkout', $graded_gradeable);
            $add_files($results, $display_version_instance->getResultsFiles(), 'results', $graded_gradeable);
            $add_files($results_public, $display_version_instance->getResultsPublicFiles(), 'results_public', $graded_gradeable);
        }
        $student_grader = false;
        if ($this->core->getUser()->getGroup() == User::GROUP_STUDENT) {
            $student_grader = true;
        }

        $submitter_id = $graded_gradeable->getSubmitter()->getId();
        $anon_submitter_id = $graded_gradeable->getSubmitter()->getAnonId($graded_gradeable->getGradeableId());
        $user_ids[$anon_submitter_id] = $submitter_id;

        print("Submissions: ");
        print_r($submissions);

        // // print "$gradeable_id and $user_id are not null\n";

        // $file_name = basename($path);
        // // print "file_name: $file_name\n";
        // $corrected_name = pathinfo($path, PATHINFO_DIRNAME) . "/" .  $file_name;
        // // print "corrected_name: $corrected_name\n";
        // $mime_type = mime_content_type($corrected_name);
        // // print "mime_type: $mime_type\n";
        // $file_type = FileUtils::getContentType($file_name);
        // // print "file_type: $file_type\n";
        // if ($mime_type === "application/pdf" || (str_starts_with($mime_type, "image/") && $mime_type !== "image/svg+xml")) {
        //     // $this->core->getOutput()->useHeader(false);
        //     // $this->core->getOutput()->useFooter(false);
        //     // header("Content-type: " . $mime_type);
        //     // header('Content-Disposition: inline; filename="' . $file_name . '"');
        //     readfile($corrected_name);
        //     // $this->core->getOutput()->renderString($path);
        //     var_dump($path);
        // }

        // $callback = function (String $course) {
        //     return $course->getCourseInfo();
        // };

        return MultiResponse::JsonOnlyResponse(
            JsonResponse::getSuccessResponse([
                "SUCCESS" => "Success",
            ])
        );

        // $this->addUploadFile("test1.txt", "", 1);

        // $controller = new SubmissionController($this->core);
        // $return = $controller->ajaxUploadSubmission('c_failure_messages');
        // // $return = $controller->ajaxUploadSubmission('hi');

    }

    /**
     * Replace the userId with the corresponding anon_id in the given file_path
     * @param string $file_path
     * @param string $g_id
     * @return string $anon_path
     */
    public function setAnonPath($file_path, $g_id)
    {
        $file_path_parts = explode("/", $file_path);
        $anon_path = "";
        for ($index = 1; $index < count($file_path_parts); $index++) {
            if ($index == 9) {
                $user_id[] = $file_path_parts[$index];
                if (!array_key_exists($user_id[0], $this->user_id_to_User_cache)) {
                    $this->user_id_to_User_cache[$user_id[0]] = $this->core->getQueries()->getUsersOrTeamsById($user_id)[$user_id[0]];
                }
                $user_or_team = $this->user_id_to_User_cache[$user_id[0]];
                $anon_id = $user_or_team->getAnonId($g_id);
                $anon_path = $anon_path . "/" . $anon_id;
            } else {
                $anon_path = $anon_path . "/" . $file_path_parts[$index];
            }
        }
        return $anon_path;
    }


    /**
     * @Route("/api/courses", methods={"GET"})
     * @Route("/home/courses", methods={"GET"})
     *
     * @param string|null $user_id
     * @param bool|string $as_instructor
     * @return MultiResponse
     */
    public function getCourses($user_id = null, $as_instructor = false)
    {
        if ($as_instructor === 'true') {
            $as_instructor = true;
        }

        $user = $this->core->getUser();
        if (is_null($user_id) || $user->getAccessLevel() !== User::LEVEL_SUPERUSER) {
            $user_id = $user->getId();
        }

        $unarchived_courses = $this->core->getQueries()->getCourseForUserId($user_id);
        $archived_courses = $this->core->getQueries()->getCourseForUserId($user_id, true);

        if ($as_instructor) {
            foreach (['archived_courses', 'unarchived_courses'] as $var) {
                $$var = array_filter($$var, function (Course $course) use ($user_id) {
                    return $this->core->getQueries()->checkIsInstructorInCourse($user_id, $course->getTitle(), $course->getSemester());
                });
            }
        }

        $callback = function (Course $course) {
            return $course->getCourseInfo();
        };

        return MultiResponse::JsonOnlyResponse(
            JsonResponse::getSuccessResponse([
                "unarchived_courses" => array_map($callback, $unarchived_courses),
                "archived_courses" => array_map($callback, $archived_courses)
            ])
        );
    }

    /**
     * @Route("/api/gradeables", methods={"GET"})
     *
     * @return MultiResponse
     */
    public function getGradeables()
    {
        // gets parameters from url
        $semester = $_GET["semester"];
        $course = $_GET["course"];


        //security feature to protect against sql injection
        if (preg_match('/[^a-z_\-0-9]/i', $semester) or preg_match('/[^a-z_\-0-9]/i', $course)) {
            die("Course and semester must be alphanumeric");
        }


        // connects to the db, config file of submitty for host and port
        $db = pg_connect("host=localhost port=5432 dbname=submitty_" . $semester . "_" . $course . " user=submitty_dbuser password=submitty_dbuser") or die("Cannot establish db connection");
        // sql selects gradeable id and gradeable title
        $query = "SELECT g_id, g_title FROM gradeable ORDER BY g_title ASC";
        // executes the query
        $rs = pg_query($db, $query) or die("Cannot execute query: $query\n");


        // $all_gradeables = $this->core->getQueries()->getAllGradeablesIdsAndTitles();
        //var_dump($all_gradeables);
        //var_dump($all_gradeables);


        $response_data = array();
        // fetches rows from response
        while ($row = pg_fetch_row($rs)) {
            // create sub array and push it to the bigger array
            $temparray = array("id" => $row[0], "title" => $row[1]);
            array_push($response_data, $temparray);
        }

        //   returning that json response.
        return MultiResponse::JsonOnlyResponse(
            JsonResponse::getSuccessResponse($response_data)
        );
    }


    /**
     * @Route("/api/ryan/gradeables", methods={"GET"})
     *
     * @return MultiResponse
     */
    public function getRyanGradeables($user_id = null)
    {
        // header("Access-Control-Allow-Origin: *");
        // header("Access-Control-Allow-Credentials: true");
        // header("Access-Control-Allow-Methods: GET, POST");

        // This goes through each course of the user, puts all gradeables into an array,
        // then for each gradeable it array_maps the info

        $user = $this->core->getUser();
        if (is_null($user_id) || $user->getAccessLevel() !== User::LEVEL_SUPERUSER) {
            $user_id = $user->getId();
        }

        $gradeables = [];
        $user_ids = [];
        // Load the gradeable information for each course
        $courses = $this->core->getQueries()->getCourseForUserId($user->getId());
        foreach ($courses as $course) {
            $gradeables_of_course = GradeableUtils::getGradeablesFromCourseApi($this->core, $course);
            var_dump($gradeables_of_course["user_ids"]);
            $gradeables = array_merge($gradeables, $gradeables_of_course["gradeables"]);
            $user_ids = array_merge($user_ids, $gradeables_of_course["user_ids"]);
        }

        $callback = function (Gradeable $gradeable) {
            return $gradeable->getGradeableInfo();
        };



        return MultiResponse::JsonOnlyResponse(
            JsonResponse::getSuccessResponse([
                "gradeable_info" => array_map($callback, $gradeables),
            ])
        );
    }

    /**
     * @Route("/home/groups")
     *
     * @param null $user_id
     * @return MultiResponse
     */
    public function getGroups($user_id = null): MultiResponse
    {
        $user = $this->core->getUser();
        if (is_null($user) || !$user->accessFaculty()) {
            return new MultiResponse(
                JsonResponse::getFailResponse("You don't have access to this endpoint."),
                new WebResponse("Error", "errorPage", "You don't have access to this page.")
            );
        }

        if (is_null($user_id) || $user->getAccessLevel() !== User::LEVEL_SUPERUSER) {
            $user_id = $user->getId();
        }

        $groups = $this->core->getQueries()->getUserGroups($user_id);

        return new MultiResponse(
            JsonResponse::getSuccessResponse($groups)
        );
    }

    /**
     * Display the HomePageView to the student.
     *
     * @Route("/home")
     * @return MultiResponse
     */
    public function showHomepage()
    {
        $courses = $this->getCourses()->json_response->json;

        return new MultiResponse(
            null,
            new WebResponse(
                ['HomePage'],
                'showHomePage',
                $this->core->getUser(),
                $courses["data"]["unarchived_courses"],
                $courses["data"]["archived_courses"]
            )
        );
    }

    /**
     * @Route("/home/courses/new", methods={"POST"})
     * @Route("/api/courses", methods={"POST"})
     */
    public function createCourse()
    {
        $user = $this->core->getUser();
        if (is_null($user) || !$user->accessFaculty()) {
            return new MultiResponse(
                JsonResponse::getFailResponse("You don't have access to this endpoint."),
                new WebResponse("Error", "errorPage", "You don't have access to this page.")
            );
        }

        if (
            !isset($_POST['course_semester'])
            || !isset($_POST['course_title'])
            || !isset($_POST['head_instructor'])
            || !isset($_POST['group_name'])
            || $_POST['group_name'] === ""
        ) {
            $error = "Semester, course title, head instructor, or group name not set.";
            $this->core->addErrorMessage($error);
            return new MultiResponse(
                JsonResponse::getFailResponse($error),
                null,
                new RedirectResponse($this->core->buildUrl(['home', 'courses', 'new']))
            );
        }

        $semester = $_POST['course_semester'];
        $course_title = strtolower($_POST['course_title']);
        $head_instructor = $_POST['head_instructor'];

        if ($user->getAccessLevel() === User::LEVEL_FACULTY && $head_instructor !== $user->getId()) {
            $error = "You can only create course for yourself.";
            $this->core->addErrorMessage($error);
            return new MultiResponse(
                JsonResponse::getFailResponse($error),
                null,
                new RedirectResponse($this->core->buildUrl(['home', 'courses', 'new']))
            );
        }

        if (empty($this->core->getQueries()->getSubmittyUser($head_instructor))) {
            $error = "Head instructor doesn't exist.";
            $this->core->addErrorMessage($error);
            return new MultiResponse(
                JsonResponse::getFailResponse($error),
                null,
                new RedirectResponse($this->core->buildUrl(['home', 'courses', 'new']))
            );
        }

        if ($this->core->getQueries()->courseExists($_POST['course_semester'], $_POST['course_title'])) {
            $error = "Course with that semester/title already exists.";
            $this->core->addErrorMessage($error);
            return new MultiResponse(
                JsonResponse::getFailResponse($error),
                null,
                new RedirectResponse($this->core->buildUrl(['home', 'courses', 'new']))
            );
        }

        $group_name = $_POST['group_name'];

        try {
            $group_check = $this->core->curlRequest(
                $this->core->getConfig()->getCgiUrl() . "group_check.cgi" . "?" . http_build_query(
                    [
                        'head_instructor' => $head_instructor,
                        'group_name' => $group_name
                    ]
                )
            );

            if (empty($group_check) || empty($group_name)) {
                $error = "Invalid group name.";
                $this->core->addErrorMessage($error);
                return new MultiResponse(
                    JsonResponse::getFailResponse($error),
                    null,
                    new RedirectResponse($this->core->buildUrl(['home', 'courses', 'new']))
                );
            }

            if (json_decode($group_check, true)['status'] === 'fail') {
                $error = "The instructor is not in the correct Linux group.\n Please contact sysadmin for more information.";
                $this->core->addErrorMessage($error);
                return new MultiResponse(
                    JsonResponse::getFailResponse($error),
                    null,
                    new RedirectResponse($this->core->buildUrl(['home', 'courses', 'new']))
                );
            }

            if (json_decode($group_check, true)['status'] === 'error') {
                $error = "The Linux group does not have the correct members for submitty use";
                $this->core->addErrorMessage($error);
                return new MultiResponse(
                    JsonResponse::getFailResponse($error),
                    null,
                    new RedirectResponse($this->core->buildUrl(['home', 'courses', 'new']))
                );
            }
        } catch (\Exception $e) {
            $error = "Server error.";
            $this->core->addErrorMessage($error);
            return new MultiResponse(
                JsonResponse::getErrorResponse($error),
                null,
                new RedirectResponse($this->core->buildUrl(['home']))
            );
        }

        $json = [
            "job" => 'CreateCourse',
            'semester' => $semester,
            'course' => $course_title,
            'head_instructor' => $head_instructor,
            'group_name' => $group_name
        ];

        $json = json_encode($json, JSON_PRETTY_PRINT);
        file_put_contents('/var/local/submitty/daemon_job_queue/create_' . $semester . '_' . $course_title . '.json', $json);

        $this->core->addSuccessMessage("Course creation request successfully sent.\n Please refresh the page later.");
        return new MultiResponse(
            JsonResponse::getSuccessResponse(null),
            null,
            new RedirectResponse($this->core->buildUrl(['home']))
        );
    }

    /**
     * @Route("/home/courses/new", methods={"GET"})
     */
    public function createCoursePage()
    {
        $user = $this->core->getUser();
        if (is_null($user) || !$user->accessFaculty()) {
            return new MultiResponse(
                JsonResponse::getFailResponse("You don't have access to this endpoint."),
                new WebResponse("Error", "errorPage", "You don't have access to this page.")
            );
        }

        if ($user->getAccessLevel() === User::LEVEL_SUPERUSER) {
            $faculty = $this->core->getQueries()->getAllFaculty();
        }

        return new MultiResponse(
            null,
            new WebResponse(
                ['HomePage'],
                'showCourseCreationPage',
                $faculty ?? null,
                $this->core->getUser()->getId(),
                $this->core->getQueries()->getAllTerms(),
                $this->core->getUser()->getAccessLevel() === User::LEVEL_SUPERUSER,
                $this->core->getCsrfToken(),
                $this->core->getQueries()->getAllCoursesForUserId($this->core->getUser()->getId())
            )
        );
    }

    /**
     * @Route("/home/group/users")
     *
     * @return MultiResponse
     */
    public function getGroupUsers($group_name = null): MultiResponse
    {
        if (!$this->core->getUser()->accessFaculty()) {
            return new MultiResponse(
                JsonResponse::getFailResponse("You don't have access to this endpoint."),
                new WebResponse("Error", "errorPage", "You don't have access to this page.")
            );
        }

        $group_file = fopen("/etc/group", "r");
        $group_content = fread($group_file, filesize("/etc/group"));
        fclose($group_file);

        $groups = explode("\n", $group_content);
        foreach ($groups as $group) {
            if (str_starts_with($group, $group_name)) {
                $categories = explode(":", $group);
                $members = array_pop($categories);
                return new MultiResponse(
                    JsonResponse::getSuccessResponse($members)
                );
            }
        }

        return new MultiResponse(
            JsonResponse::getErrorResponse("Group not found")
        );
    }

    /**
     * @Route("/term/new", methods={"POST"})
     * @return MultiResponse
     */
    public function addNewTerm()
    {
        if (!$this->core->getUser()->isSuperUser()) {
            return new MultiResponse(
                JsonResponse::getFailResponse("You don't have access to this endpoint."),
                new WebResponse("Error", "errorPage", "You don't have access to this page.")
            );
        }
        $response = new MultiResponse();
        if (isset($_POST['term_id']) && isset($_POST['term_name']) && isset($_POST['start_date']) && isset($_POST['end_date'])) {
            $term_id = $_POST['term_id'];
            $term_name = $_POST['term_name'];
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];

            $terms = $this->core->getQueries()->getAllTerms();
            if (in_array($term_id, $terms)) {
                $this->core->addErrorMessage("Term id already exists.");
            } elseif ($end_date < $start_date) {
                $this->core->addErrorMessage("End date should be after Start date.");
            } else {
                $this->core->getQueries()->createNewTerm($term_id, $term_name, $start_date, $end_date);
                $this->core->addSuccessMessage("Term added successfully.");
            }
            $url = $this->core->buildUrl(['home', 'courses', 'new']);
            $response = $response->RedirectOnlyResponse(new RedirectResponse($url));
        }
        return $response;
    }

    /**
     * @Route("/update", methods={"GET"})
     * @return MultiResponse|WebResponse
     */
    public function systemUpdatePage()
    {
        $user = $this->core->getUser();
        if (is_null($user) || $user->getAccessLevel() !== User::LEVEL_SUPERUSER) {
            return new MultiResponse(
                JsonResponse::getFailResponse("You don't have access to this endpoint."),
                new WebResponse("Error", "errorPage", "You don't have access to this page.")
            );
        }

        $this->core->getOutput()->addInternalJs('system-update.js');
        $this->core->getOutput()->addInternalCss('system-update.css');
        return new WebResponse(
            'HomePage',
            'showSystemUpdatePage',
            $this->core->getCsrfToken()
        );
    }
}
