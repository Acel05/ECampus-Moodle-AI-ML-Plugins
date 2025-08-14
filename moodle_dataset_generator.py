#!/usr/bin/env python3
"""
Synthetic Moodle Dataset Generator

This script generates a realistic Moodle-like dataset for training machine learning models
to predict student performance. The data resembles what would be extracted from a real
Moodle database, with appropriate correlations between activities and outcomes.

The outcome (pass/fail) is determined by a grade threshold of 70%.
"""

import pandas as pd
import numpy as np
from datetime import datetime, timedelta
import random
import os
from faker import Faker
import argparse

# Set random seed for reproducibility
np.random.seed(42)
random.seed(42)
faker = Faker()
Faker.seed(42)


def weighted_choice(choices, weights):
    """Make a weighted random choice from a list of options."""
    return np.random.choice(choices, p=weights)


def generate_synthetic_moodle_data(num_students=500, num_courses=5, 
                                  start_date='2023-01-01', end_date='2023-12-31',
                                  pass_threshold=70, max_grade=100,
                                  output_format='csv'):
    """
    Generate synthetic Moodle data for student performance prediction.

    Args:
        num_students: Number of students to generate
        num_courses: Number of courses to simulate
        start_date: Start date for the academic period
        end_date: End date for the academic period
        pass_threshold: Grade threshold for passing (0-100)
        max_grade: Maximum possible grade
        output_format: Output file format (csv or json)

    Returns:
        DataFrame containing synthetic Moodle data
    """
    print(f"Generating synthetic Moodle data for {num_students} students across {num_courses} courses...")

    # Convert dates to datetime objects
    start_date = datetime.strptime(start_date, '%Y-%m-%d')
    end_date = datetime.strptime(end_date, '%Y-%m-%d')

    # Create student profiles with base characteristics
    student_data = []

    # Define student engagement profiles
    engagement_profiles = {
        'high': {
            'activity_level_range': (15, 30),
            'login_frequency_range': (5, 7),
            'module_completion_range': (0.8, 1.0),
            'assignment_completion_range': (0.9, 1.0),
            'forum_activity_range': (10, 25),
            'submission_timeliness_range': (0.8, 1.0),  # % of assignments submitted on time
            'grade_range': (65, 100)
        },
        'medium': {
            'activity_level_range': (8, 15),
            'login_frequency_range': (3, 5),
            'module_completion_range': (0.5, 0.8),
            'assignment_completion_range': (0.6, 0.9),
            'forum_activity_range': (5, 12),
            'submission_timeliness_range': (0.5, 0.8),
            'grade_range': (50, 85)
        },
        'low': {
            'activity_level_range': (1, 8),
            'login_frequency_range': (1, 3),
            'module_completion_range': (0.1, 0.5),
            'assignment_completion_range': (0.2, 0.6),
            'forum_activity_range': (0, 5),
            'submission_timeliness_range': (0.0, 0.5),
            'grade_range': (0, 70)
        }
    }

    # Generate a distribution of student engagement profiles
    engagement_distribution = {
        'high': 0.25,    # 25% high-engaged students
        'medium': 0.45,  # 45% medium-engaged students
        'low': 0.3       # 30% low-engaged students
    }

    # Create course data
    courses = []
    for i in range(1, num_courses + 1):
        course_id = 100 + i
        course_name = f"Course {i}: {faker.bs()}"
        course_modules = random.randint(10, 20)  # Number of modules in course
        course_assignments = random.randint(5, 10)  # Number of assignments
        course_quizzes = random.randint(3, 8)  # Number of quizzes
        course_forums = random.randint(2, 5)  # Number of discussion forums

        courses.append({
            'id': course_id,
            'name': course_name,
            'modules': course_modules,
            'assignments': course_assignments,
            'quizzes': course_quizzes,
            'forums': course_forums,
            'start_date': start_date + timedelta(days=random.randint(0, 30)),
            'end_date': end_date - timedelta(days=random.randint(0, 30))
        })

    # Generate student data
    for student_id in range(1, num_students + 1):
        # Create a base profile for each student
        student_profile = {
            'user_id': student_id,
            'firstname': faker.first_name(),
            'lastname': faker.last_name(),
            'email': faker.email(),
            'country': faker.country(),
            'timezone': random.choice(['UTC', 'UTC+1', 'UTC-5', 'UTC+8', 'UTC-8']),
            'firstaccess': start_date - timedelta(days=random.randint(10, 60)),
        }

        # Assign an engagement profile to this student
        engagement_level = weighted_choice(
            list(engagement_distribution.keys()), 
            list(engagement_distribution.values())
        )
        profile = engagement_profiles[engagement_level]

        # For each course, generate course-specific data
        for course in courses:
            # Some students might not be enrolled in all courses
            if random.random() > 0.9:  # 10% chance to skip a course
                continue

            # Base course access pattern
            lastaccess = course['start_date'] + timedelta(
                days=random.randint(0, (course['end_date'] - course['start_date']).days)
            )

            # Activity level - how many actions the student performs per week
            activity_level = random.randint(*profile['activity_level_range'])

            # Login frequency - days per week
            login_frequency = random.uniform(*profile['login_frequency_range'])

            # Module completion
            total_modules = course['modules']
            completed_modules = int(total_modules * random.uniform(*profile['module_completion_range']))

            # Assignment submissions
            total_assignments = course['assignments']
            submitted_assignments = int(total_assignments * random.uniform(*profile['assignment_completion_range']))

            # Late submissions (inverse of timeliness)
            late_submissions = int(submitted_assignments * (1 - random.uniform(*profile['submission_timeliness_range'])))

            # Quiz attempts
            total_quizzes = course['quizzes']
            quiz_attempts = int(total_quizzes * random.uniform(*profile['assignment_completion_range']))

            # Forum activity
            forum_posts = int(random.uniform(*profile['forum_activity_range']))
            forum_reads = forum_posts * random.randint(3, 10)  # Students read more than they post

            # Final grade calculation with some noise
            base_grade = random.uniform(*profile['grade_range'])

            # Add correlation between activities and grade
            activity_factor = 0.7  # Weight of activities on grade
            random_factor = 0.3    # Random factor for grade

            # Calculate a normalized activity score (0-1)
            max_activity = max(profile['activity_level_range'][1], 1)
            max_modules = course['modules']
            max_assignments = course['assignments']
            max_forum_posts = profile['forum_activity_range'][1]

            activity_score = (
                activity_level / max_activity * 0.3 +
                completed_modules / max_modules * 0.3 +
                submitted_assignments / max_assignments * 0.3 +
                (forum_posts / max_forum_posts if max_forum_posts > 0 else 0) * 0.1
            )

            # Calculate final grade with correlation to activities
            grade_with_correlation = (
                base_grade * random_factor +  # Random component
                activity_score * activity_factor * max_grade  # Activity component
            )

            # Ensure grade is within bounds
            final_grade = min(max(0, grade_with_correlation), max_grade)

            # Determine pass/fail based on threshold
            passed = 1 if final_grade >= pass_threshold else 0

            # Round the grade to integer for realism
            final_grade = round(final_grade)

            # Compile all data for this student in this course
            course_data = {
                'user_id': student_id,
                'courseid': course['id'],
                'course_name': course['name'],
                'days_since_last_access': (datetime.now() - lastaccess).days,
                'days_since_first_access': (datetime.now() - student_profile['firstaccess']).days,
                'activity_level': activity_level,
                'submission_count': submitted_assignments,
                'modules_accessed': completed_modules,
                'forum_posts': forum_posts,
                'forum_reads': forum_reads,
                'login_frequency': login_frequency,
                'total_modules': total_modules,
                'completed_modules': completed_modules,
                'total_assignments': total_assignments,
                'submitted_assignments': submitted_assignments,
                'late_submissions': late_submissions,
                'total_quizzes': total_quizzes,
                'quiz_attempts': quiz_attempts,
                'current_grade': final_grade,
                'current_grade_percentage': final_grade,  # Since we're using 0-100 scale
                'engagement_level': engagement_level,  # Meta-information
                'final_outcome': passed  # Target variable (1=pass, 0=fail)
            }

            # Merge base student profile with course data
            full_record = {**student_profile, **course_data}
            student_data.append(full_record)

    # Convert to DataFrame
    df = pd.DataFrame(student_data)

    # Add a few more calculated features that might be useful
    df['completion_ratio'] = df['completed_modules'] / df['total_modules']
    df['submission_ratio'] = df['submitted_assignments'] / df['total_assignments'].apply(lambda x: max(x, 1))
    df['timeliness_ratio'] = 1 - (df['late_submissions'] / df['submitted_assignments'].apply(lambda x: max(x, 1)))
    df['quiz_participation'] = df['quiz_attempts'] / df['total_quizzes'].apply(lambda x: max(x, 1))
    df['forum_engagement'] = df['forum_posts'] / df['forum_reads'].apply(lambda x: max(x, 1))

    # Add some correlations between final outcome and calculated metrics
    # to make the dataset more realistic for ML training
    noise_level = 0.05
    for student_idx in range(len(df)):
        # Students with high completion ratios tend to pass
        if df.loc[student_idx, 'completion_ratio'] > 0.8 and random.random() > noise_level:
            df.loc[student_idx, 'final_outcome'] = 1

        # Students with very low submission ratios tend to fail
        if df.loc[student_idx, 'submission_ratio'] < 0.3 and random.random() > noise_level:
            df.loc[student_idx, 'final_outcome'] = 0

        # Ensure grade and outcome are consistent
        if df.loc[student_idx, 'current_grade'] >= pass_threshold:
            df.loc[student_idx, 'final_outcome'] = 1
        else:
            df.loc[student_idx, 'final_outcome'] = 0

    print(f"Generated {len(df)} student records across {num_courses} courses")

    # Print class distribution
    pass_count = df['final_outcome'].sum()
    fail_count = len(df) - pass_count
    print(f"Class distribution: Pass: {pass_count} ({pass_count/len(df):.1%}), Fail: {fail_count} ({fail_count/len(df):.1%})")

    return df


def save_dataset(df, filename_prefix, output_format='csv', output_dir='.'):
    """Save the generated dataset to file."""
    os.makedirs(output_dir, exist_ok=True)

    timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
    filename = f"{filename_prefix}_{timestamp}.{output_format}"
    filepath = os.path.join(output_dir, filename)

    if output_format.lower() == 'csv':
        df.to_csv(filepath, index=False)
    elif output_format.lower() == 'json':
        df.to_json(filepath, orient='records')
    elif output_format.lower() == 'excel':
        df.to_excel(filepath, index=False)
    else:
        raise ValueError(f"Unsupported output format: {output_format}")

    print(f"Dataset saved to {filepath}")
    return filepath


def main():
    parser = argparse.ArgumentParser(description='Generate synthetic Moodle dataset for ML training')
    parser.add_argument('--students', type=int, default=500, help='Number of students')
    parser.add_argument('--courses', type=int, default=5, help='Number of courses')
    parser.add_argument('--start-date', type=str, default='2023-01-01', help='Start date (YYYY-MM-DD)')
    parser.add_argument('--end-date', type=str, default='2023-12-31', help='End date (YYYY-MM-DD)')
    parser.add_argument('--pass-threshold', type=int, default=70, help='Grade threshold for passing (0-100)')
    parser.add_argument('--max-grade', type=int, default=100, help='Maximum possible grade')
    parser.add_argument('--format', type=str, default='csv', choices=['csv', 'json', 'excel'],
                        help='Output file format')
    parser.add_argument('--output-dir', type=str, default='.', help='Directory to save output files')

    args = parser.parse_args()

    # Generate and save the dataset
    df = generate_synthetic_moodle_data(
        num_students=args.students,
        num_courses=args.courses,
        start_date=args.start_date,
        end_date=args.end_date,
        pass_threshold=args.pass_threshold,
        max_grade=args.max_grade,
        output_format=args.format
    )

    # Save the dataset
    filepath = save_dataset(
        df, 
        filename_prefix='moodle_dataset',
        output_format=args.format,
        output_dir=args.output_dir
    )

    # Display some statistics
    print("\nDataset Statistics:")
    print(f"Total records: {len(df)}")
    print(f"Number of unique students: {df['user_id'].nunique()}")
    print(f"Number of unique courses: {df['courseid'].nunique()}")

    # Feature distribution
    print("\nFeature Distributions (Mean):")
    numeric_columns = ['activity_level', 'submission_count', 'modules_accessed',
                      'forum_posts', 'login_frequency', 'current_grade',
                      'completion_ratio', 'submission_ratio', 'timeliness_ratio']

    for col in numeric_columns:
        if col in df.columns:
            print(f"{col}: {df[col].mean():.2f}")

    # Correlation with target
    print("\nCorrelation with target (final_outcome):")
    for col in numeric_columns:
        if col in df.columns:
            corr = df[col].corr(df['final_outcome'])
            print(f"{col}: {corr:.2f}")

    # Sample of the data
    print("\nSample data (first 5 rows):")
    print(df.head(5).to_string())

    return filepath


if __name__ == "__main__":
    main()
